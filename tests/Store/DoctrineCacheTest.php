<?php

namespace Dafiti\HttpCache\Store;

use Doctrine\Common\Cache\ArrayCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class DoctrineCacheTest extends \PHPUnit_Framework_TestCase
{
    private $store;

    public function setUp()
    {
        $this->store = new DoctrineCache();
    }

    public function tearDown()
    {
        $this->store = null;
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache
     */
    public function testShouldInstanceOfHttpCacheStore()
    {
        $this->assertInstanceOf('\Symfony\Component\HttpKernel\HttpCache\StoreInterface', $this->store);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::lock
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldReturnTrueIfLockIsAcquired()
    {
        $request = Request::create('/test');

        $this->assertTrue($this->store->lock($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::lock
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldReturnKeyIfLockNotWorksOnSave()
    {
        $driver = $this->getMockBuilder('\Doctrine\Common\Cache\ArrayCache')
            ->setMethods(['save', 'contains'])
            ->getMock();

        $driver->expects($this->once())
            ->method('save')
            ->will($this->returnValue(false));

        $driver->expects($this->once())
            ->method('contains')
            ->will($this->returnValue(true));

        $store = new DoctrineCache($driver);
        $request = Request::create('/test');

        $this->assertNotEmpty($store->lock($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::unlock
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldReturnFalseWhenUnlockInvalidRequest()
    {
        $request = Request::create('/test');

        $this->assertFalse($this->store->unlock($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::unlock
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldReturnTrueIfUnlockWorks()
    {
        $request = Request::create('/test');
        $this->store->lock($request);

        $this->assertTrue($this->store->unlock($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::isLocked
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldCheckIfRequestIsNotLocked()
    {
        $request = Request::create('/test');

        $this->assertFalse($this->store->isLocked($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::isLocked
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldCheckIfRequestIsLocked()
    {
        $request = Request::create('/test');
        $this->store->lock($request);

        $this->assertTrue($this->store->isLocked($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::cleanup
     */
    public function testShouldReturnErrorWhenCleanupStorageFail()
    {
        $request1 = Request::create('/test1');
        $request2 = Request::create('/test2');

        $this->store->lock($request1);
        $this->store->lock($request2);

        $php = \PHPUnit_Extension_FunctionMocker::start($this, 'Dafiti\HttpCache\Store')
            ->mockFunction('error_get_last')
            ->mockFunction('headers_sent')
            ->mockFunction('header')
            ->getMock();

        $php->expects($this->once())
            ->method('error_get_last')
            ->will($this->returnValue(['type' => 1]));

        $php->expects($this->once())
            ->method('headers_sent')
            ->will($this->returnValue(false));

        $php->expects($this->at(2))
            ->method('header')
            ->with('HTTP/1.0 503 Service Unavailable');

        $php->expects($this->at(3))
            ->method('header')
            ->with('Retry-After: 10');

        $this->store->cleanup();

        $this->expectOutputString('503 Service Unavailable');
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::cleanup
     */
    public function testShouldCleanupStorage()
    {
        $request1 = Request::create('/test1');
        $request2 = Request::create('/test2');

        $this->store->lock($request1);
        $this->store->lock($request2);

        $this->store->cleanup();

        $this->assertFalse($this->store->isLocked($request1));
        $this->assertFalse($this->store->isLocked($request2));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::purge
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldReturnFalseWhenPurgeInvalidCachedRequest()
    {
        $url = '/test';

        $this->assertFalse($this->store->purge($url));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::purge
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     */
    public function testShouldReturnTrueWhenPurgeCache()
    {
        $url = '/test';

        $request = Request::create($url);
        $response = new Response('Hello Test', 200);

        $this->store->write($request, $response);
        $this->assertTrue($this->store->purge($url));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::write
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateContentDigest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     *
     * @expectedException RuntimeException
     * @expectedExceptionMessage Unable to store the entity
     */
    public function testShouldThrowExceptionWhenSaveResponseContent()
    {
        $driver = $this->getMockBuilder('\Doctrine\Common\Cache\ArrayCache')
            ->setMethods(['fetch'])
            ->getMock();

        $driver->expects($this->once())
            ->method('fetch')
            ->will($this->returnValue(''));

        $store = new DoctrineCache($driver);

        $request  = Request::create('/test');
        $response = new Response('Hello Test', 200);

        $store->write($request, $response);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::write
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateContentDigest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistRequest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistResponse
     *
     * @expectedException RuntimeException
     * @expectedExceptionMessage Unable to store the metadata
     */
    public function testShouldThrowExceptionWhenStoreMetadata()
    {
        $request  = Request::create('/test');
        $response = new Response('Hello Test', 200);

        $driver = $this->getMockBuilder('\Doctrine\Common\Cache\ArrayCache')
            ->setMethods(['fetch'])
            ->getMock();

        $driver->expects($this->at(0))
            ->method('fetch')
            ->will($this->returnValue($response->getContent()));

        $driver->expects($this->at(1))
            ->method('fetch')
            ->will($this->returnValue(''));

        $store = new DoctrineCache($driver);

        $store->write($request, $response);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::write
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateContentDigest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistRequest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistResponse
     */
    public function testShouldStoreACacheEntry()
    {
        $request  = Request::create('/test', 'get');
        $response = new Response('Hello Test', 200);

        $cacheKey = $this->store->write($request, $response);

        $this->assertNotEmpty($cacheKey);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::write
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateContentDigest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistRequest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistResponse
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::requestsMatch
     */
    public function testShouldRewriteACacheEntry()
    {
        $request  = Request::create('/test', 'get');
        $response = new Response('Hello Test', 200);

        $this->store->write($request, $response);

        $cacheKey = $this->store->write($request, $response);

        $this->assertNotEmpty($cacheKey);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::write
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateContentDigest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistRequest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistResponse
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::requestsMatch
     */
    public function testShouldRewriteACacheEntryWithVaryHeader()
    {
        $request  = Request::create('/test', 'get');
        $response = new Response('Hello Test', 200, ['Vary' => 'Foo Bar']);

        $this->store->write($request, $response);

        $cacheKey = $this->store->write($request, $response);

        $this->assertNotEmpty($cacheKey);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateContentDigest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::generateCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistRequest
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::persistResponse
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::requestsMatch
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::write
     */
    public function testShouldRewriteACacheWithMultipleResponsesForEachVaryCombination()
    {
        $request1 = Request::create('/test', 'get', [], [], [], ['HTTP_FOO' => 'Foo', 'HTTP_BAR' => 'Bar']);
        $request2 = Request::create('/test', 'get', [], [], [], ['HTTP_FOO' => 'Ozzy', 'HTTP_BAR' => 'Osbourne']);
        $response = new Response('Hello Test', 200, ['Vary' => 'Foo Bar']);

        $this->store->write($request1, $response);

        $cacheKey = $this->store->write($request2, $response);

        $this->assertNotEmpty($cacheKey);
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::lookup
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     */
    public function testLookupShouldRetrieveNullWhenMetadataNotExists()
    {
        $request = Request::create('/test1');
        $result  = $this->store->lookup($request);

        $this->assertNull($result);
    }
    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::lookup
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::requestsMatch
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::restoreResponse
     */
    public function testLookupShouldRetrieveNullWhenNotFindAnEntry()
    {
        $driver = new ArrayCache();
        $store  = new DoctrineCache($driver);

        $request  = Request::create('/test');
        $response = new Response('Hello Test', 200, ['Cache-Control' => 'max-age=200']);

        $store->write($request, $response);

        $key = $response->headers->get('X-Content-Digest');

        $driver->delete($key);

        $this->assertNull($store->lookup($request));
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::lookup
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::requestsMatch
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::restoreResponse
     */
    public function testLookupShouldRetrieveCachedResponse()
    {
        $request  = Request::create('/test1');
        $response = new Response('Hello Test', 200);

        $this->store->write($request, $response);

        $result = $this->store->lookup($request);

        $this->assertInstanceOf('\Symfony\Component\HttpFoundation\Response', $result);
        $this->assertEquals($response->getContent(), $result->getContent());
        $this->assertEquals($response->getStatusCode(), $result->getStatusCode());
    }

    /**
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::invalidate
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getCacheKey
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::getMetadata
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::load
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::restoreResponse
     * @covers \Dafiti\HttpCache\Store\DoctrineCache::save
     */
    public function testInvalidatesMetaAndEntityStoreEntriesWithInvalidate()
    {
        $request  = Request::create('/test1');
        $response = new Response('Hello Test', 200);

        $this->store->write($request, $response);
        $this->store->invalidate($request);

        $response = $this->store->lookup($request);

        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Response', $response);
        $this->assertFalse($response->isFresh());
    }
}
