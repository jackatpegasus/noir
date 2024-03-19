namespace RollingCurl;

class Request
{
   
    private $url;
    private $method;
    private $postData;
    private $headers;
    private $options = array();
    private $extraInfo;
    private $responseText;
    private $responseInfo;
    private $responseError;
    private $responseErrno;

    function __construct($url, $method="GET")
    {
        $this->setUrl($url);
        $this->setMethod($method);
    }

    
    public function setExtraInfo($extraInfo)
    {
        $this->extraInfo = $extraInfo;
        return $this;
    }

     public function getExtraInfo()
    {
        return $this->extraInfo;
    }

    public function setHeaders($headers)
    {
        $this->headers = $headers;
        return $this;
    }
    public function getHeaders()
    {
        return $this->headers;
    }

    public function setMethod($method)
    {
        $this->method = $method;
        return $this;
    }

    public function getMethod()
    {
        return $this->method;
    }
    public function setOptions($options)
    {
        if (!is_array($options)) {
            throw new \InvalidArgumentException("options must be an array");
        }
        $this->options = $options;
        return $this;
    }
    public function addOptions($options)
    {
        if (!is_array($options)) {
            throw new \InvalidArgumentException("options must be an array");
        }
        $this->options = $options + $this->options;
        return $this;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setPostData($postData)
    {
        $this->postData = $postData;
        return $this;
    }

    public function getPostData()
    {
        return $this->postData;
    }

    public function setResponseErrno($responseErrno)
    {
        $this->responseErrno = $responseErrno;
        return $this;
    }
    public function getResponseErrno()
    {
        return $this->responseErrno;
    }

    public function setResponseError($responseError)
    {
        $this->responseError = $responseError;
        return $this;
    }

    public function getResponseError()
    {
        return $this->responseError;
    }
    public function setResponseInfo($responseInfo)
    {
        $this->responseInfo = $responseInfo;
        return $this;
    }

    /**
     * @return array
     */
    public function getResponseInfo()
    {
        return $this->responseInfo;
    }

    public function setResponseText($responseText)
    {
        $this->responseText = $responseText;
        return $this;
    }

    public function getResponseText()
    {
        return $this->responseText;
    }

    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    public function getUrl()
    {
        return $this->url;
    }
}
