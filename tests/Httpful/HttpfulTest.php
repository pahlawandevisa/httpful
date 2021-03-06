<?php
/**
 * Port over the original tests into a more traditional PHPUnit
 * format.  Still need to hook into a lightweight HTTP server to
 * better test some things (e.g. obscure cURL settings).  I've moved
 * the old tests and node.js server to the tests/.legacy directory.
 *
 * @author Nate Good <me@nategood.com>
 */

namespace Httpful\Test;

use Httpful\Bootstrap;
use Httpful\Exception\ConnectionErrorException;
use Httpful\Handlers\JsonHandler;
use Httpful\Handlers\MimeHandlerAdapter;
use Httpful\Handlers\XmlHandler;
use Httpful\Http;
use Httpful\Httpful;
use Httpful\Mime;
use Httpful\Request;
use Httpful\Response;
use PHPUnit\Framework\TestCase;

require dirname(dirname(__DIR__)) . '/bootstrap.php';

Bootstrap::init();

/** @noinspection PhpUndefinedConstantInspection */
define('TEST_SERVER', WEB_SERVER_HOST . ':' . WEB_SERVER_PORT);

/** @noinspection PhpMultipleClassesDeclarationsInOneFile */

/**
 * Class HttpfulTest
 *
 * @package Httpful\Test
 */
class HttpfulTest extends TestCase
{
  const TEST_SERVER  = TEST_SERVER;

  const TEST_URL     = 'http://127.0.0.1:8008';

  const TEST_URL_400 = 'http://127.0.0.1:8008/400';

  // INFO: Travis-CI can't handle e.g. "10.255.255.1" or "http://www.google.com:81"
  const TIMEOUT_URI  = 'http://suckup.de/timeout.php';

  const SAMPLE_JSON_HEADER   =
      "HTTP/1.1 200 OK
Content-Type: application/json
Connection: keep-alive
Transfer-Encoding: chunked\r\n";
  const SAMPLE_JSON_RESPONSE = '{"key":"value","object":{"key":"value"},"array":[1,2,3,4]}';
  const SAMPLE_CSV_HEADER    =
      "HTTP/1.1 200 OK
Content-Type: text/csv
Connection: keep-alive
Transfer-Encoding: chunked\r\n";
  const SAMPLE_CSV_RESPONSE  =
      'Key1,Key2
Value1,Value2
"40.0","Forty"';
  const SAMPLE_XML_RESPONSE  = '<stdClass><arrayProp><array><k1><myClass><intProp>2</intProp></myClass></k1></array></arrayProp><stringProp>a string</stringProp><boolProp>TRUE</boolProp></stdClass>';
  const SAMPLE_XML_HEADER    =
      "HTTP/1.1 200 OK
Content-Type: application/xml
Connection: keep-alive
Transfer-Encoding: chunked\r\n";
  const SAMPLE_VENDOR_HEADER =
      "HTTP/1.1 200 OK
Content-Type: application/vnd.nategood.message+xml
Connection: keep-alive
Transfer-Encoding: chunked\r\n";
  const SAMPLE_VENDOR_TYPE   = 'application/vnd.nategood.message+xml';
  const SAMPLE_MULTI_HEADER  =
      "HTTP/1.1 200 OK
Content-Type: application/json
Connection: keep-alive
Transfer-Encoding: chunked
X-My-Header:Value1
X-My-Header:Value2\r\n";

  /**
   * init
   */
  public function testInit()
  {
    $r = Request::init();
    // Did we get a 'Request' object?
    self::assertSame('Httpful\Request', get_class($r));
  }

  public function testDetermineLength()
  {
    $r = Request::init();
    self::assertSame(1, $r->_determineLength('A'));
    self::assertSame(2, $r->_determineLength('À'));
    self::assertSame(2, $r->_determineLength('Ab'));
    self::assertSame(3, $r->_determineLength('Àb'));
    self::assertSame(6, $r->_determineLength('世界'));
  }

  public function testMethods()
  {
    $valid_methods = array('get', 'post', 'delete', 'put', 'options', 'head');
    $url = 'http://example.com/';
    foreach ($valid_methods as $method) {
      $r = call_user_func(array('Httpful\Request', $method), $url);
      self::assertSame('Httpful\Request', get_class($r));
      self::assertSame(strtoupper($method), $r->method);
    }
  }

  public function testDefaults()
  {
    // Our current defaults are as follows
    $r = Request::init();
    self::assertSame(Http::GET, $r->method);
    self::assertFalse($r->strict_ssl);
  }

  public function testShortMime()
  {
    // Valid short ones
    self::assertSame(Mime::JSON, Mime::getFullMime('json'));
    self::assertSame(Mime::XML, Mime::getFullMime('xml'));
    self::assertSame(Mime::HTML, Mime::getFullMime('html'));
    self::assertSame(Mime::CSV, Mime::getFullMime('csv'));
    self::assertSame(Mime::UPLOAD, Mime::getFullMime('upload'));

    // Valid long ones
    self::assertSame(Mime::JSON, Mime::getFullMime(Mime::JSON));
    self::assertSame(Mime::XML, Mime::getFullMime(Mime::XML));
    self::assertSame(Mime::HTML, Mime::getFullMime(Mime::HTML));
    self::assertSame(Mime::CSV, Mime::getFullMime(Mime::CSV));
    self::assertSame(Mime::UPLOAD, Mime::getFullMime(Mime::UPLOAD));

    // No false positives
    self::assertNotEquals(Mime::XML, Mime::getFullMime(Mime::HTML));
    self::assertNotEquals(Mime::JSON, Mime::getFullMime(Mime::XML));
    self::assertNotEquals(Mime::HTML, Mime::getFullMime(Mime::JSON));
    self::assertNotEquals(Mime::XML, Mime::getFullMime(Mime::CSV));
  }

  public function testSettingStrictSsl()
  {
    $r = Request::init()
                ->withStrictSSL();

    self::assertTrue($r->strict_ssl);

    $r = Request::init()
                ->withoutStrictSSL();

    self::assertFalse($r->strict_ssl);
  }

  public function testSendsAndExpectsType()
  {
    $r = Request::init()
                ->sendsAndExpectsType(Mime::JSON);
    self::assertSame(Mime::JSON, $r->expected_type);
    self::assertSame(Mime::JSON, $r->content_type);

    $r = Request::init()
                ->sendsAndExpectsType('html');
    self::assertSame(Mime::HTML, $r->expected_type);
    self::assertSame(Mime::HTML, $r->content_type);

    $r = Request::init()
                ->sendsAndExpectsType('form');
    self::assertSame(Mime::FORM, $r->expected_type);
    self::assertSame(Mime::FORM, $r->content_type);

    $r = Request::init()
                ->sendsAndExpectsType('application/x-www-form-urlencoded');
    self::assertSame(Mime::FORM, $r->expected_type);
    self::assertSame(Mime::FORM, $r->content_type);

    $r = Request::init()
                ->sendsAndExpectsType(Mime::CSV);
    self::assertSame(Mime::CSV, $r->expected_type);
    self::assertSame(Mime::CSV, $r->content_type);
  }

  public function testIni()
  {
    // Test setting defaults/templates

    // Create the template
    $template = Request::init()
                       ->method(Http::POST)
                       ->withStrictSSL()
                       ->expectsType(Mime::HTML)
                       ->sendsType(Mime::FORM);

    Request::ini($template);

    $r = Request::init();

    self::assertTrue($r->strict_ssl);
    self::assertSame(Http::POST, $r->method);
    self::assertSame(Mime::HTML, $r->expected_type);
    self::assertSame(Mime::FORM, $r->content_type);

    // Test the default accessor as well
    self::assertTrue(Request::d('strict_ssl'));
    self::assertSame(Http::POST, Request::d('method'));
    self::assertSame(Mime::HTML, Request::d('expected_type'));
    self::assertSame(Mime::FORM, Request::d('content_type'));

    Request::resetIni();
  }

  public function testAccept()
  {
    $r = Request::get('http://example.com/')
                ->expectsType(Mime::JSON);

    self::assertSame(Mime::JSON, $r->expected_type);
    $r->_curlPrep();
    self::assertContains('application/json', $r->raw_headers);
  }

  public function testCustomAccept()
  {
    $accept = 'application/api-1.0+json';
    $r = Request::get('http://example.com/')
                ->addHeader('Accept', $accept);

    $r->_curlPrep();
    self::assertContains($accept, $r->raw_headers);
    self::assertSame($accept, $r->headers['Accept']);
  }

  public function testUserAgent()
  {
    $r = Request::get('http://example.com/')
                ->withUserAgent('ACME/1.2.3');

    self::assertArrayHasKey('User-Agent', $r->headers);
    $r->_curlPrep();
    self::assertContains('User-Agent: ACME/1.2.3', $r->raw_headers);
    self::assertNotContains('User-Agent: HttpFul/1.0', $r->raw_headers);

    $r = Request::get('http://example.com/')
                ->withUserAgent('');

    self::assertArrayHasKey('User-Agent', $r->headers);
    $r->_curlPrep();
    self::assertContains('User-Agent:', $r->raw_headers);
    self::assertNotContains('User-Agent: HttpFul/1.0', $r->raw_headers);
  }

  public function testAuthSetup()
  {
    $username = 'nathan';
    $password = 'opensesame';

    $r = Request::get('http://example.com/')
                ->authenticateWith($username, $password);

    self::assertSame($username, $r->username);
    self::assertSame($password, $r->password);
    self::assertTrue($r->hasBasicAuth());
  }

  public function testDigestAuthSetup()
  {
    $username = 'nathan';
    $password = 'opensesame';

    $r = Request::get('http://example.com/')
                ->authenticateWithDigest($username, $password);

    self::assertSame($username, $r->username);
    self::assertSame($password, $r->password);
    self::assertTrue($r->hasDigestAuth());
  }

  public function testJsonResponseParse()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);

    self::assertSame('value', $response->body->key);
    self::assertSame('value', $response->body->object->key);
    self::assertInternalType('array', $response->body->array);
    self::assertSame(1, $response->body->array[0]);
  }

  public function testXMLResponseParse()
  {
    $req = Request::init()->sendsAndExpects(Mime::XML);
    $response = new Response(self::SAMPLE_XML_RESPONSE, self::SAMPLE_XML_HEADER, $req);
    $sxe = $response->body;
    self::assertSame('object', gettype($sxe));
    self::assertSame('SimpleXMLElement', get_class($sxe));
    $bools = $sxe->xpath('/stdClass/boolProp');
    foreach ($bools as $bool) {
      self::assertSame('TRUE', (string)$bool);
    }
    $ints = $sxe->xpath('/stdClass/arrayProp/array/k1/myClass/intProp');
    foreach ($ints as $int) {
      self::assertSame('2', (string)$int);
    }
    $strings = $sxe->xpath('/stdClass/stringProp');
    foreach ($strings as $string) {
      self::assertSame('a string', (string)$string);
    }
  }

  public function testCsvResponseParse()
  {
    $req = Request::init()->sendsAndExpects(Mime::CSV);
    $response = new Response(self::SAMPLE_CSV_RESPONSE, self::SAMPLE_CSV_HEADER, $req);

    self::assertSame('Key1', $response->body[0][0]);
    self::assertSame('Value1', $response->body[1][0]);
    self::assertInternalType('string', $response->body[2][0]);
    self::assertSame('40.0', $response->body[2][0]);
  }

  public function testParsingContentTypeCharset()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    // $response = new Response(SAMPLE_JSON_RESPONSE, "", $req);
    // // Check default content type of iso-8859-1
    $response = new Response(
        self::SAMPLE_JSON_RESPONSE, "HTTP/1.1 200 OK
Content-Type: text/plain; charset=utf-8\r\n", $req
    );
    self::assertInstanceOf('Httpful\Response\Headers', $response->headers);
    self::assertSame($response->headers['Content-Type'], 'text/plain; charset=utf-8');
    self::assertSame($response->content_type, 'text/plain');
    self::assertSame($response->charset, 'utf-8');
  }

  public function testParsingContentTypeUpload()
  {
    $req = Request::init();

    $req->sendsType(Mime::UPLOAD);
    // $response = new Response(SAMPLE_JSON_RESPONSE, "", $req);
    // // Check default content type of iso-8859-1
    self::assertSame($req->content_type, 'multipart/form-data');
  }

  public function testAttach()
  {
    $req = Request::init();
    /** @noinspection RealpathOnRelativePathsInspection */
    $testsPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
    $filename = $testsPath . DIRECTORY_SEPARATOR . '/static/test_image.jpg';
    $req->attach(array('index' => $filename));
    $payload = $req->payload['index'];
    // PHP 5.5  + will take advantage of CURLFile while previous
    // versions just use the string syntax
    if (is_string($payload)) {
      self::assertSame($payload, '@' . $filename . ';type=image/jpeg');
    } else {
      self::assertInstanceOf('CURLFile', $payload);
    }

    self::assertSame($req->content_type, Mime::UPLOAD);
    self::assertSame($req->serialize_payload_method, Request::SERIALIZE_PAYLOAD_NEVER);
  }

  public function testIsUpload()
  {
    $req = Request::init();

    $req->sendsType(Mime::UPLOAD);

    self::assertTrue($req->isUpload());
  }

  public function testEmptyResponseParse()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response('', self::SAMPLE_JSON_HEADER, $req);
    self::assertSame(null, $response->body);

    $reqXml = Request::init()->sendsAndExpects(Mime::XML);
    $responseXml = new Response('', self::SAMPLE_XML_HEADER, $reqXml);
    self::assertSame(null, $responseXml->body);
  }

  public function testNoAutoParse()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON)->withoutAutoParsing();
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    self::assertInternalType('string', $response->body);
    $req = Request::init()->sendsAndExpects(Mime::JSON)->withAutoParsing();
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    self::assertInternalType('object', $response->body);
  }

  public function testParseHeaders()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    self::assertSame('application/json', $response->headers['Content-Type']);
  }

  public function testRawHeaders()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    self::assertContains('Content-Type: application/json', $response->raw_headers);
  }

  public function testHasErrors()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response('', "HTTP/1.1 100 Continue\r\n", $req);
    self::assertFalse($response->hasErrors());
    $response = new Response('', "HTTP/1.1 200 OK\r\n", $req);
    self::assertFalse($response->hasErrors());
    $response = new Response('', "HTTP/1.1 300 Multiple Choices\r\n", $req);
    self::assertFalse($response->hasErrors());
    $response = new Response('', "HTTP/1.1 400 Bad Request\r\n", $req);
    self::assertTrue($response->hasErrors());
    $response = new Response('', "HTTP/1.1 500 Internal Server Error\r\n", $req);
    self::assertTrue($response->hasErrors());
  }

  public function testWhenError()
  {
    $caught = false;

    try {
      /** @noinspection PhpUnusedParameterInspection */
      Request::get('malformed:url')
             ->whenError(
                 function ($error) use (&$caught) {
                   $caught = true;
                 }
             )
             ->timeoutIn(0.1)
             ->send();
    } catch (ConnectionErrorException $e) {
    }

    self::assertTrue($caught);
  }

  public function testBeforeSend()
  {
    $invoked = false;
    $changed = false;
    $self = $this;

    try {
      Request::get('malformed://url')
             ->beforeSend(
                 function ($request) use (&$invoked, $self) {

                   /* @var Request $request */

                   $self::assertSame('malformed://url', $request->uri);
                   $self::assertSame('A payload', $request->serialized_payload);
                   $request->uri('malformed2://url');
                   $invoked = true;
                 }
             )
             ->whenError(
                 function ($error) { /* Be silent */
                 }
             )
             ->body('A payload')
             ->send();
    } catch (ConnectionErrorException $e) {
      self::assertTrue(strpos($e->getMessage(), 'malformed2') !== false);
      $changed = true;
    }

    self::assertTrue($invoked);
    self::assertTrue($changed);
  }

  public function test_parseCode()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    $code = $response->_parseCode("HTTP/1.1 406 Not Acceptable\r\n");
    self::assertSame(406, $code);
  }

  public function testToString()
  {
    $req = Request::init()->sendsAndExpects(Mime::JSON);
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    self::assertSame(self::SAMPLE_JSON_RESPONSE, (string)$response);
  }

  public function test_parseHeaders()
  {
    $parse_headers = Response\Headers::fromString(self::SAMPLE_JSON_HEADER);
    self::assertCount(3, $parse_headers);
    self::assertSame('application/json', $parse_headers['Content-Type']);
    self::assertTrue(isset($parse_headers['Connection']));
  }

  public function testMultiHeaders()
  {
    $req = Request::init();
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_MULTI_HEADER, $req);
    $parse_headers = $response->_parseHeaders(self::SAMPLE_MULTI_HEADER);
    self::assertSame('Value1,Value2', $parse_headers['X-My-Header']);
  }

  public function testDetectContentType()
  {
    $req = Request::init();
    $response = new Response(self::SAMPLE_JSON_RESPONSE, self::SAMPLE_JSON_HEADER, $req);
    self::assertSame('application/json', $response->headers['Content-Type']);
  }

  public function testMissingBodyContentType()
  {
    $body = 'A string';
    $request = Request::post(self::TEST_URL, $body)->_curlPrep();
    self::assertSame($body, $request->serialized_payload);
  }

  public function testParentType()
  {
    // Parent type
    $request = Request::init()->sendsAndExpects(Mime::XML);
    $response = new Response('<xml><name>Nathan</name></xml>', self::SAMPLE_VENDOR_HEADER, $request);

    self::assertSame('application/xml', $response->parent_type);
    self::assertSame(self::SAMPLE_VENDOR_TYPE, $response->content_type);
    self::assertTrue($response->is_mime_vendor_specific);

    // Make sure we still parsed as if it were plain old XML
    self::assertSame('Nathan', (string)$response->body->name);
  }

  public function testMissingContentType()
  {
    // Parent type
    $request = Request::init()->sendsAndExpects(Mime::XML);
    $response = new Response(
        '<xml><name>Nathan</name></xml>',
        "HTTP/1.1 200 OK
Connection: keep-alive
Transfer-Encoding: chunked\r\n", $request
    );

    self::assertSame('', $response->content_type);
  }

  public function testCustomMimeRegistering()
  {
    // Register new mime type handler for "application/vnd.nategood.message+xml"
    Httpful::register(self::SAMPLE_VENDOR_TYPE, new DemoMimeHandler());

    self::assertTrue(Httpful::hasParserRegistered(self::SAMPLE_VENDOR_TYPE));

    $request = Request::init();
    $response = new Response('<xml><name>Nathan</name></xml>', self::SAMPLE_VENDOR_HEADER, $request);

    self::assertSame(self::SAMPLE_VENDOR_TYPE, $response->content_type);
    self::assertSame('custom parse', $response->body);
  }

  public function testShorthandMimeDefinition()
  {
    $r = Request::init()->expects('json');
    self::assertSame(Mime::JSON, $r->expected_type);

    $r = Request::init()->expectsJson();
    self::assertSame(Mime::JSON, $r->expected_type);
  }

  public function testOverrideXmlHandler()
  {
    // Lazy test...
    $prev = Httpful::get(Mime::XML);
    self::assertEquals($prev, new XmlHandler());
    $conf = array('namespace' => 'http://example.com');
    Httpful::register(Mime::XML, new XmlHandler($conf));
    $new = Httpful::get(Mime::XML);
    self::assertNotEquals($prev, $new);
  }

  public function testHasProxyWithoutProxy()
  {
    $r = Request::get('someUrl');
    self::assertFalse($r->hasProxy());
  }

  public function testHasProxyWithProxy()
  {
    $r = Request::get('some_other_url');
    $r->useProxy('proxy.com');
    self::assertTrue($r->hasProxy());
  }

  public function testHasProxyWithEnvironmentProxy()
  {
    putenv('http_proxy=http://127.0.0.1:300/');
    $r = Request::get('some_other_url');
    self::assertTrue($r->hasProxy());

    // reset
    putenv('http_proxy=');
  }

  // problem with Travis-CI
  /*
  public function testTimeout()
  {
    try {
      Request::init()
             ->uri(self::TIMEOUT_URI)
             ->timeout(0.1)
             ->send();
    } catch (ConnectionErrorException $e) {
      self::assertTrue(is_resource($e->getCurlObject()));
      self::assertTrue($e->wasTimeout());

      return;
    }

    self::assertFalse(true);
  }
  */

  public function testParseJSON()
  {
    $handler = new JsonHandler();

    $bodies = array(
        'foo',
        array(),
        array('foo', 'bar'),
        null,
    );
    foreach ($bodies as $body) {
      self::assertSame($body, $handler->parse(json_encode($body)));
    }

    try {
      /** @noinspection OnlyWritesOnParameterInspection */
      /** @noinspection PhpUnusedLocalVariableInspection */
      $result = $handler->parse('invalid{json');
    } catch (\Exception $e) {
      self::assertSame('Unable to parse response as JSON', $e->getMessage());

      return;
    }

    self::fail('Expected an exception to be thrown due to invalid json');
  }

  public function testParams()
  {
    $r = Request::get('http://google.com');
    $r->_curlPrep();
    $r->_uriPrep();
    self::assertSame('http://google.com', $r->uri);

    $r = Request::get('http://google.com?q=query');
    $r->_curlPrep();
    $r->_uriPrep();
    self::assertSame('http://google.com?q=query', $r->uri);

    $r = Request::get('http://google.com');
    $r->param('a', 'b');
    $r->_curlPrep();
    $r->_uriPrep();
    self::assertSame('http://google.com?a=b', $r->uri);

    $r = Request::get('http://google.com?a=b');
    $r->param('c', 'd');
    $r->_curlPrep();
    $r->_uriPrep();
    self::assertSame('http://google.com?a=b&c=d', $r->uri);

    $r = Request::get('http://google.com?a=b');
    $r->param('', 'e');
    $r->_curlPrep();
    $r->_uriPrep();
    self::assertSame('http://google.com?a=b', $r->uri);

    $r = Request::get('http://google.com?a=b');
    $r->param('e', '');
    $r->_curlPrep();
    $r->_uriPrep();
    self::assertSame('http://google.com?a=b', $r->uri);
  }

  // /**
  //  * Skeleton for testing against the 5.4 baked in server
  //  */
  // public function testLocalServer()
  // {
  //     if (!defined('WITHOUT_SERVER') || (defined('WITHOUT_SERVER') && !WITHOUT_SERVER)) {
  //         // PHP test server seems to always set content type to application/octet-stream
  //         // so force parsing as JSON here
  //         Httpful::register('application/octet-stream', new \Httpful\Handlers\JsonHandler());
  //         $response = Request::get(TEST_SERVER . '/test.json')
  //             ->sendsAndExpects(MIME::JSON);
  //         $response->send();
  //         self::assertTrue(...);
  //     }
  // }
}

/** @noinspection PhpMultipleClassesDeclarationsInOneFile */

/**
 * Class DemoMimeHandler
 *
 * @package Httpful\Test
 */
class DemoMimeHandler extends MimeHandlerAdapter
{
  /** @noinspection PhpMissingParentCallCommonInspection */
  /**
   * @param string $body
   *
   * @return string
   */
  public function parse($body)
  {
    return 'custom parse';
  }
}
