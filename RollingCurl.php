namespace RollingCurl;
use RollingCurl\Request;
class RollingCurl
{
    private $simultaneousLimit = 0;
    private $callback;
    private $idleCallback;

    protected $options = array(
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.111 Safari/537.36',
    );
   protected $multicurlOptions = array();
   private $headers = array();
   private $pendingRequests = array();
   private $pendingRequestsPosition = 0;
   private $activeRequests = array();
   private $completedRequests = array();
   private $completedRequestCount = 0;

    public function add(Request $request)
    {
        $this->pendingRequests[] = $request;

        return $this;
    }

    public function request($url, $method = "GET", $postData = null, $headers = null, $options = null)
    {
        $newRequest = new Request($url, $method);
        if ($postData) {
            $newRequest->setPostData($postData);
        }
        if ($headers) {
            $newRequest->setHeaders($headers);
        }
        if ($options) {
            $newRequest->setOptions($options);
        }
        return $this->add($newRequest);
    }

    public function get($url, $headers = null, $options = null)
    {
        return $this->request($url, "GET", null, $headers, $options);
    }

    public function post($url, $postData = null, $headers = null, $options = null)
    {
        return $this->request($url, "POST", $postData, $headers, $options);
    }

    public function put($url, $putData = null, $headers = null, $options = null)
    {
        return $this->request($url, "PUT", $putData, $headers, $options);
    }


    public function delete($url, $headers = null, $options = null)
    {
        return $this->request($url, "DELETE", null, $headers, $options);
    }

    /**
     * Run all queued requests
     *
     * @return void
     */
    public function execute()
    {

        $master = curl_multi_init();
        foreach ($this->multicurlOptions AS $multiOption => $multiValue) {
            curl_multi_setopt($master, $multiOption, $multiValue);
        }

        // start the first batch of requests
        $firstBatch = $this->getNextPendingRequests($this->getSimultaneousLimit());

        // what a silly "error"
        if (count($firstBatch) == 0) {
            return;
        }

        foreach ($firstBatch as $request) {
            // setup the curl request, queue it up, and put it in the active array
            $ch      = curl_init();
            $options = $this->prepareRequestOptions($request);
            curl_setopt_array($ch, $options);
            curl_multi_add_handle($master, $ch);
            $this->activeRequests[(int) $ch] = $request;
        }

        $active = null;

        // Use a shorter select timeout when there is something to do between calls
        $idleCallback = $this->idleCallback;
        $selectTimeout = $idleCallback ? 0.1 : 1.0;

        do {

            // ensure we're running
            $status = curl_multi_exec($master, $active);
            // see if there is anything to read
            while ($transfer = curl_multi_info_read($master)) {

                // get the request object back and put the curl response into it
                $key     = (int) $transfer['handle'];
                $request = $this->activeRequests[$key];
                $request->setResponseText(curl_multi_getcontent($transfer['handle']));
                $request->setResponseErrno(curl_errno($transfer['handle']));
                $request->setResponseError(curl_error($transfer['handle']));
                $request->setResponseInfo(curl_getinfo($transfer['handle']));

                // remove the request from the list of active requests
                unset($this->activeRequests[$key]);

                // move the request to the completed set
                $this->completedRequests[] = $request;
                $this->completedRequestCount++;

                // start a new request (it's important to do this before removing the old one)
                if ($nextRequest = $this->getNextPendingRequest()) {
                    // setup the curl request, queue it up, and put it in the active array
                    $ch      = curl_init();
                    $options = $this->prepareRequestOptions($nextRequest);
                    curl_setopt_array($ch, $options);
                    curl_multi_add_handle($master, $ch);
                    $this->activeRequests[(int) $ch] = $nextRequest;
                }

                // remove the curl handle that just completed
                curl_multi_remove_handle($master, $transfer['handle']);

                // if there is a callback, run it
                if (is_callable($this->callback)) {
                    $callback = $this->callback;
                    $callback($request, $this);
                }

                // if something was requeued, this will get it running/update our loop check values
                $status = curl_multi_exec($master, $active);

            }

            // Error detection -- this is very, very rare
            $err = null;
            switch ($status) {
                case CURLM_BAD_EASY_HANDLE:
                    $err = 'CURLM_BAD_EASY_HANDLE';
                    break;
                case CURLM_OUT_OF_MEMORY:
                    $err = 'CURLM_OUT_OF_MEMORY';
                    break;
                case CURLM_INTERNAL_ERROR:
                    $err = 'CURLM_INTERNAL_ERROR';
                    break;
                case CURLM_BAD_HANDLE:
                    $err = 'CURLM_BAD_HANDLE';
                    break;
            }
            if ($err) {
                throw new \Exception("curl_multi_exec failed with error code ($status) const ($err)");
            }

            // Block until *something* happens to avoid burning CPU cycles for naught
            while(0 == curl_multi_select($master, $selectTimeout) && $idleCallback) {
                $idleCallback($this);
            }

            // see if we're done yet or not
        } while ($status === CURLM_CALL_MULTI_PERFORM || $active);

        curl_multi_close($master);

    }


    /**
     * Helper function to gather all the curl options: global, inferred, and per request
     *
     * @param Request $request
     * @return array
     */
    private function prepareRequestOptions(Request $request)
    {

        // options for this entire curl object
        $options = $this->getOptions();

        // set the request URL
        $options[CURLOPT_URL] = $request->getUrl();

        // set the request method
        $options[CURLOPT_CUSTOMREQUEST] = $request->getMethod();

        // posting data w/ this request?
        if ($request->getPostData()) {
            $options[CURLOPT_POST]       = 1;
            $options[CURLOPT_POSTFIELDS] = $request->getPostData();
        }

        // if the request has headers, use those, or if there are global headers, use those
        if ($request->getHeaders()) {
            $options[CURLOPT_HEADER]     = 0;
            $options[CURLOPT_HTTPHEADER] = $request->getHeaders();
        } elseif ($this->getHeaders()) {
            $options[CURLOPT_HEADER]     = 0;
            $options[CURLOPT_HTTPHEADER] = $this->getHeaders();
        }

        // if the request has options set, use those and have them take precedence
        if ($request->getOptions()) {
            $options = $request->getOptions() + $options;
        }

        return $options;
    }

    /**
     * Define a callable to handle the response. 
     * 
     * It can be an anonymous function:
     *
     *     $rc = new RollingCurl();
     *     $rc->setCallback(function($request, $rolling_curl) {
     *         // process
     *     });
     *
     * Or an existing function:
     *
     *     class MyClass {
     *         function doCurl() {
     *             $rc = new RollingCurl();
     *             $rc->setCallback(array($this, 'callback'));
     *         }
     *
     *         // Cannot be private or protected
     *         public function callback($request, $rolling_curl) {
     *             // process
     *         } 
     *     }
     *
     * The called code should expect two parameters: \RollingCurl\Request $request, \RollingCurl\RollingCurl $rollingCurl
     *   $request is original request object, but now with body, headers, response code, etc
     *   $rollingCurl is the rolling curl object itself (useful if you want to re/queue a URL)
     *
     * @param callable $callback
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function setCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException("must pass in a callable instance");
        }
        $this->callback = $callback;
        return $this;
    }

    /**
     * @return callable
     */
    public function getCallback()
    {
        return $this->callback;
    }

    /** Define a callable to be called when waiting for responses.
     *
     * @param callable $callback
     * @return RollingCurl
     */
    public function setIdleCallback(callable $callback)
    {
        $this->idleCallback = $callback;
        return $this;
    }

    /**
     *
     * @return callable
     */
    public function getIdleCallback()
    {
        return $this->idleCallback;
    }


    /**
     * @param array $headers
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function setHeaders($headers)
    {
        if (!is_array($headers)) {
            throw new \InvalidArgumentException("headers must be an array");
        }
        $this->headers = $headers;
        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param array $options
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function setOptions($options)
    {
        if (!is_array($options)) {
            throw new \InvalidArgumentException("options must be an array");
        }
        $this->options = $options;
        return $this;
    }

    /**
     * Override and add options
     *
     * @param array $options
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function addOptions($options)
    {
        if (!is_array($options)) {
            throw new \InvalidArgumentException("options must be an array");
        }
        $this->options = $options + $this->options;
        return $this;
    }

    /**
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * @param array $multicurlOptions
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function setMulticurlOptions($multicurlOptions)
    {
        if (!is_array($multicurlOptions)) {
            throw new \InvalidArgumentException("multicurlOptions must be an array");
        }
        $this->multicurlOptions = $multicurlOptions;
        return $this;
    }

    /**
     * Override and add multicurlOptions
     *
     * @param array $multicurlOptions
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function addMulticurlOptions($multicurlOptions)
    {
        if (!is_array($multicurlOptions)) {
            throw new \InvalidArgumentException("multicurlOptions must be an array");
        }
        $this->multicurlOptions = $multicurlOptions + $this->multicurlOptions;
        return $this;
    }

    /**
     * @return array
     */
    public function getMulticurlOptions()
    {
        return $this->multicurlOptions;
    }

    /**
     * Set the limit for how many cURL requests will be execute simultaneously.
     *
     * Please be mindful that if you set this too high, requests are likely to fail
     * more frequently or automated software may perceive you as a DOS attack and
     * automatically block further requests.
     *
     * @param int $count
     * @throws \InvalidArgumentException
     * @return RollingCurl
     */
    public function setSimultaneousLimit($count)
    {
        if (!is_int($count) || $count < 1) {
            throw new \InvalidArgumentException("setSimultaneousLimit count must be an int >= 1");
        }
        $this->simultaneousLimit = $count;
        return $this;
    }

    /**
     * @return int
     */
    public function getSimultaneousLimit()
    {
        return $this->simultaneousLimit;
    }

    /**
     * @return Request[]
     */
    public function getCompletedRequests()
    {
        return $this->completedRequests;
    }

    /**
     * Return the next $limit pending requests (may return an empty array)
     *
     * If you pass $limit <= 0 you will get all the pending requests back
     *
     * @param int $limit
     * @return Request[] May be empty
     */
    private function getNextPendingRequests($limit = 1)
    {
        $requests = array();
        while ($limit--) {
            if (!isset($this->pendingRequests[$this->pendingRequestsPosition])) {
                break;
            }
            $requests[] = $this->pendingRequests[$this->pendingRequestsPosition];
            $this->pendingRequestsPosition++;
        }
        return $requests;
    }

    /**
     * Get the next pending request, or return null
     *
     * @return null|Request
     */
    private function getNextPendingRequest()
    {
        $next = $this->getNextPendingRequests();
        return count($next) ? $next[0] : null;
    }

    /**
     * Removes requests from the queue that have already been processed
     *
     * Beceause the request queue does not shrink during processing
     * (merely traversed), it is sometimes necessary to prune the queue.
     * This method creates a new array starting at the first un-processed
     * request, replaces the old queue and resets counters.
     *
     * @return RollingCurl
     */
    public function prunePendingRequestQueue()
    {
        $this->pendingRequests = $this->getNextPendingRequests(0);
        $this->pendingRequestsPosition = 0;
        return $this;
    }

    /**
     * @param bool $useArray count the completedRequests array is true. Otherwise use the global counter.
     * @return int
     */
    public function countCompleted($useArray=false)
    {
        return $useArray ? count($this->completedRequests) : $this->completedRequestCount;
    }

    /**
     * @return int
     */
    public function countPending()
    {
        return count($this->pendingRequests) - $this->pendingRequestsPosition;
    }

    /**
     * @return int
     */
    public function countActive()
    {
        return count($this->activeRequests);
    }

    /**
     * Clear out all completed requests
     *
     * If you are running a very large number of requests, it's a good
     * idea to call this every few completed requests so you don't run
     * out of memory.
     *
     * @return RollingCurl
     */
    public function clearCompleted()
    {
        $this->completedRequests = array();
        gc_collect_cycles();
        return $this;
    }

}
