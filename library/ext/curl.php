<?php

class swoole_curl_handler
{
    /** @var Swoole\Coroutine\Http\Client */
    private $client;
    private $info;
    private $outputStream;

    /** @var callable */
    private $headerFunction;
    /** @var callable */
    private $readFunction;
    /** @var callable */
    private $writeFunction;
    /** @var callable */
    private $progressFunction;

    public $returnTransfer = true;
    public $method = 'GET';
    public $headers = [];

    function create(string $url)
    {
        $info = parse_url($url);
        $ssl = $info['scheme'] === 'https';
        if (empty($info['port'])) {
            $port = $ssl ? 443 : 80;
        } else {
            $port = intval($info['port']);
        }
        $this->info = $info;
        $this->client = new Swoole\Coroutine\Http\Client($info['host'], $port, $ssl);
    }

    function execute()
    {
        $client = $this->client;
        $client->setMethod($this->method);
        if ($this->headers) {
            $client->setHeaders($this->headers);
        }
        if (!$client->execute($this->getUrl())) {
            return false;
        }

        if ($client->headers and $this->headerFunction) {
            $cb = $this->headerFunction;
            if ($client->statusCode === 200) {
                $cb($this, "HTTP/1.1 200 OK\r\n");
            }
            foreach ($client->headers as $k => $v) {
                $cb($this, "$k: $v\r\n");
            }
            $cb($this, '');
        }

        if ($client->body and $this->readFunction) {
            $cb = $this->readFunction;
            $cb($this, $this->outputStream, strlen($client->body));
        }

        if ($this->returnTransfer) {
            return $client->body;
        } else {
            if ($this->outputStream) {
                return fwrite($this->outputStream, $client->body) === strlen($client->body);
            } else {
                echo $this->outputStream;
            }
            return true;
        }
    }

    function close(): void
    {
        $this->client = null;
    }

    function getErrorCode(): int
    {
        return $this->client->errCode;
    }

    function getErrorMsg(): string
    {
        return $this->client->errMsg;
    }

    private function getUrl(): string
    {
        if (empty($this->info['path'])) {
            $url = '/';
        } else {
            $url = $this->info['path'];
        }
        if (!empty($this->info['query'])) {
            $url .= '?' . $this->info['query'];
        }
        if (!empty($this->info['query'])) {
            $url .= '#' . $this->info['fragment'];
        }
        return $url;
    }

    function setOption(int $opt, $value): bool
    {
        switch ($opt) {
            case CURLOPT_URL:
                $this->create($value);
                break;
            case CURLOPT_RETURNTRANSFER:
                $this->returnTransfer = $value;
                break;
            case CURLOPT_ENCODING:
                if (empty($value)) {
                    $value = 'gzip';
                }
                $this->headers['Accept-Encoding'] = $value;
                break;
            case CURLOPT_POST:
                $this->method = 'POST';
                break;
            case CURLOPT_HTTPHEADER:
                foreach ($value as $header) {
                    list($k, $v) = explode(':', $header);
                    $v = trim($v);
                    if ($v) {
                        $this->headers[$k] = $v;
                    }
                }
                break;
            case CURLOPT_CUSTOMREQUEST:
                break;
            case CURLOPT_PROTOCOLS:
                if ($value > 3) {
                    throw new swoole_curl_exception("option[{$opt}={$value}] not supported");
                }
                break;
            case CURLOPT_HTTP_VERSION:
                break;
            case CURLOPT_SSL_VERIFYHOST:
                break;
            case CURLOPT_SSL_VERIFYPEER:
                $this->client->set(['ssl_verify_peer' => $value]);
                break;
            case CURLOPT_CONNECTTIMEOUT:
                $this->client->set(['connect_timeout' => $value]);
                break;
            case CURLOPT_FILE:
                $this->outputStream = $value;
                break;
            case CURLOPT_HEADER:
                break;
            case CURLOPT_HEADERFUNCTION:
                $this->headerFunction = $value;
                break;
            case CURLOPT_READFUNCTION:
                $this->readFunction = $value;
                break;
            case CURLOPT_WRITEFUNCTION:
                $this->writeFunction = $value;
                break;
            case CURLOPT_PROGRESSFUNCTION:
                $this->progressFunction = $value;
                break;
            default:
                throw new swoole_curl_exception("option[{$opt}] not supported");
        }
        return true;
    }

    function reset(): void
    {
        $this->client->body = '';
    }
}

class swoole_curl_exception extends swoole_exception
{

}

function swoole_curl_init(): swoole_curl_handler
{
    return new swoole_curl_handler();
}

function swoole_curl_setopt(swoole_curl_handler $obj, $opt, $value): bool
{
    return $obj->setOption($opt, $value);
}

function swoole_curl_setopt_array(swoole_curl_handler $obj, $array): bool
{
    foreach ($array as $k => $v) {
        if ($obj->setOption($k, $v) === false) {
            return false;
        }
    }
    return true;
}

function swoole_curl_exec(swoole_curl_handler $obj)
{
    return $obj->execute();
}

function swoole_curl_close(swoole_curl_handler $obj): void
{
    $obj->close();
}

function swoole_curl_error(swoole_curl_handler $obj): string
{
    return $obj->getErrorMsg();
}

function swoole_curl_errno(swoole_curl_handler $obj): int
{
    return $obj->getErrorCode();
}

function swoole_curl_reset(swoole_curl_handler $obj): void
{
    $obj->reset();
}
