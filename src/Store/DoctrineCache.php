<?php

namespace Dafiti\HttpCache\Store;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpCache\StoreInterface;

class DoctrineCache implements StoreInterface
{
    /**
     * @var \SplObjectStorage
     */
    private $keyCache;

    /**
     * @var array
     */
    private $locks = [];

    /**
     * @var \Doctrine\Common\Cache\Cache
     */
    private $driver;

    public function __construct(Cache $driver = null)
    {
        $this->keyCache = new \SplObjectStorage();

        if (is_null($driver)) {
            $driver = new ArrayCache();
        }

        $this->driver = $driver;
    }

    /**
     * Locates a cached Response for the Request provided.
     *
     * @param Request $request A Request instance
     *
     * @return Response|null A Response instance, or null if no cache entry was found
     */
    public function lookup(Request $request)
    {
        $key = $this->getCacheKey($request);

        if (!$entries = $this->getMetadata($key)) {
            return;
        }

        // find a cached entry that matches the request.
        $match = null;

        foreach ($entries as $entry) {
            $vary = '';

            if (isset($entry[1]['vary'][0])) {
                $vary = implode(', ', $entry[1]['vary']);
            }

            if ($this->requestsMatch($vary, $request->headers->all(), $entry[0])) {
                $match = $entry;
                break;
            }
        }

        if (null === $match) {
            return;
        }

        list($request, $headers) = $match;

        $body = $this->driver->fetch($headers['x-content-digest'][0]);

        if ($body) {
            return $this->restoreResponse($headers, $body);
        }

        return;
    }

    /**
     * Writes a cache entry to the store for the given Request and Response.
     *
     * Existing entries are read and any that match the response are removed. This
     * method calls write with the new list of cache entries.
     *
     * @param Request  $request  A Request instance
     * @param Response $response A Response instance
     *
     * @return string The key under which the response is stored
     */
    public function write(Request $request, Response $response)
    {
        // write the response body to the entity store if this is the original response
        if (!$response->headers->has('X-Content-Digest')) {
            $digest = $this->generateContentDigest($response);

            if (false === $this->save($digest, $response->getContent())) {
                throw new \RuntimeException('Unable to store the entity.');
            }

            $response->headers->set('X-Content-Digest', $digest);

            if (!$response->headers->has('Transfer-Encoding')) {
                $response->headers->set('Content-Length', strlen($response->getContent()));
            }
        }

        $key = $this->getCacheKey($request);
        $storedEnv = $this->persistRequest($request);

        // read existing cache entries, remove non-varying, and add this one to the list
        $entries = array();
        $vary = $response->headers->get('vary');

        foreach ($this->getMetadata($key) as $entry) {
            if (!isset($entry[1]['vary'][0])) {
                $entry[1]['vary'] = array('');
            }

            if ($vary != $entry[1]['vary'][0] || !$this->requestsMatch($vary, $entry[0], $storedEnv)) {
                $entries[] = $entry;
            }
        }

        $headers = $this->persistResponse($response);

        unset($headers['age']);

        array_unshift($entries, array($storedEnv, $headers));

        if (false === $this->save($key, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }

        return $key;
    }

    /**
     * Invalidates all cache entries that match the request.
     *
     * @param Request $request A Request instance
     */
    public function invalidate(Request $request)
    {
        $modified = false;
        $key = $this->getCacheKey($request);

        $entries = [];

        foreach ($this->getMetadata($key) as $entry) {
            $response = $this->restoreResponse($entry[1]);

            if ($response->isFresh()) {
                $response->expire();

                $modified = true;
                $entries[] = [
                    $entry[0],
                    $this->persistResponse($response),
                ];

                continue;
            }

            $entries[] = $entry;
        }

        if ($modified && false === $this->save($key, serialize($entries))) {
            throw new \RuntimeException('Unable to store the metadata.');
        }
    }

    /**
     * Locks the cache for a given Request.
     *
     * @param Request $request A Request instance
     *
     * @return bool|string true if the lock is acquired, the path to the current lock otherwise
     */
    public function lock(Request $request)
    {
        $key = $this->getCacheKey($request).'.lck';

        if (false !== $this->driver->save($key, 'lock')) {
            $this->locks[] = $key;

            return true;
        }

        return !$this->driver->contains($key) ?: $key;
    }

    /**
     * Releases the lock for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return bool False if the lock file does not exist or cannot be unlocked, true otherwise
     */
    public function unlock(Request $request)
    {
        $key = $this->getCacheKey($request).'.lck';

        return $this->driver->contains($key) ? $this->driver->delete($key) : false;
    }

    /**
     * Returns whether or not a lock exists.
     *
     * @param Request $request A Request instance
     *
     * @return bool true if lock exists, false otherwise
     */
    public function isLocked(Request $request)
    {
        $key = $this->getCacheKey($request).'.lck';

        return $this->driver->contains($key);
    }

    /**
     * Purges data for the given URL.
     *
     * @param string $url A URL
     *
     * @return bool true if the URL exists and has been purged, false otherwise
     */
    public function purge($url)
    {
        $key = $this->getCacheKey(Request::create($url));

        if ($this->driver->contains($key)) {
            return $this->driver->delete($key);
        }

        return false;
    }

    /**
     * Cleanups storage.
     */
    public function cleanup()
    {
        //unlock everything
        foreach ($this->locks as $lock) {
            $this->driver->delete($lock);
        }

        $error = error_get_last();

        if (1 === $error['type'] && false === headers_sent()) {
            header('HTTP/1.0 503 Service Unavailable');
            header('Retry-After: 10');
            echo '503 Service Unavailable';
        }
    }

    /**
     * Generates a cache key for the given Request.
     *
     * This method should return a key that must only depend on a
     * normalized version of the request URI.
     *
     * If the same URI can have more than one representation, based on some
     * headers, use a Vary header to indicate them, and each representation will
     * be stored independently under the same cache key.
     *
     * @param Request $request A Request instance
     *
     * @return string A key for the given Request
     */
    private function generateCacheKey(Request $request)
    {
        return 'md'.hash('sha256', $request->getUri());
    }

    /**
     * Returns a cache key for the given Request.
     *
     * @param Request $request A Request instance
     *
     * @return string A key for the given Request
     */
    private function getCacheKey(Request $request)
    {
        if (isset($this->keyCache[$request])) {
            return $this->keyCache[$request];
        }

        return $this->keyCache[$request] = $this->generateCacheKey($request);
    }

    /**
     * Returns content digest for $response.
     *
     * @param Response $response
     *
     * @return string
     */
    private function generateContentDigest(Response $response)
    {
        return 'en'.hash('sha256', $response->getContent());
    }

    /**
     * Save data for the given key.
     *
     * @param string $key  The store key
     * @param string $data The data to store
     *
     * @return bool
     */
    private function save($key, $data)
    {
        $this->driver->save($key, $data);

        if ($data !== $this->driver->fetch($key)) {
            return false;
        }

        return true;
    }

    /**
     * Persists the Request HTTP headers.
     *
     * @param Request $request A Request instance
     *
     * @return array An array of HTTP headers
     */
    private function persistRequest(Request $request)
    {
        return $request->headers->all();
    }

    /**
     * Gets all data associated with the given key.
     *
     * Use this method only if you know what you are doing.
     *
     * @param string $key The store key
     *
     * @return array An array of data associated with the key
     */
    private function getMetadata($key)
    {
        $entries = $this->load($key);

        if (false === $entries) {
            return array();
        }

        return unserialize($entries);
    }

    /**
     * Loads data for the given key.
     *
     * @param string $key The store key
     *
     * @return string The data associated with the key
     */
    private function load($key)
    {
        if ($this->driver->contains($key)) {
            return $this->driver->fetch($key);
        }

        return false;
    }

    /**
     * Persists the Response HTTP headers.
     *
     * @param Response $response A Response instance
     *
     * @return array An array of HTTP headers
     */
    private function persistResponse(Response $response)
    {
        $headers = $response->headers->all();
        $headers['X-Status'] = array($response->getStatusCode());

        return $headers;
    }

    /**
     * Determines whether two Request HTTP header sets are non-varying based on
     * the vary response header value provided.
     *
     * @param string $vary A Response vary header
     * @param array  $env1 A Request HTTP header array
     * @param array  $env2 A Request HTTP header array
     *
     * @return bool true if the two environments match, false otherwise
     */
    private function requestsMatch($vary, $env1, $env2)
    {
        if (empty($vary)) {
            return true;
        }

        foreach (preg_split('/[\s,]+/', $vary) as $header) {
            $key = strtr(strtolower($header), '_', '-');

            $v1 = isset($env1[$key]) ? $env1[$key] : null;
            $v2 = isset($env2[$key]) ? $env2[$key] : null;

            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;
    }

    /**
     * Restores a Response from the HTTP headers and body.
     *
     * @param array  $headers An array of HTTP headers for the Response
     * @param string $body    The Response body
     *
     * @return Response
     */
    private function restoreResponse($headers, $body = null)
    {
        $status = $headers['X-Status'][0];
        unset($headers['X-Status']);

        if (null !== $body) {
            $headers['X-Body-Eval'] = 'SSI';
        }

        return new Response($body, $status, $headers);
    }
}
