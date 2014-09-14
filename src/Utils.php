<?php
namespace GuzzleHttp;

use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\ErrorEvent;
use GuzzleHttp\Event\RequestEvents;

/**
 * Utility methods used throughout Guzzle.
 */
final class Utils
{
    /**
     * Sends multiple requests in parallel and returns an array of responses
     * and exceptions that uses the same ordering as the provided requests.
     *
     * Note: This method keeps every request and response in memory, and as
     * such is NOT recommended when sending a large number or an indeterminable
     * number of requests in parallel.
     *
     * @param ClientInterface $client   Client used to send the requests
     * @param array|\Iterator $requests Requests to send in parallel
     * @param array           $options  Passes through the options available in
     *                                  {@see GuzzleHttp\ClientInterface::sendAll()}
     *
     * @return array Array of {@see GuzzleHttp\Message\ResponseInterface} if
     *     a request succeeded or a {@see GuzzleHttp\Exception\RequestException}
     *     if it failed. The order of the resulting array is the same order as
     *     the requests that were provided.
     * @throws \InvalidArgumentException if the event format is incorrect.
     */
    public static function batch(
        ClientInterface $client,
        $requests,
        array $options = []
    ) {
        $hash = new \SplObjectStorage();
        foreach ($requests as $request) {
            $hash->attach($request);
        }

        $events = ['complete', 'error'];
        $fn = function ($e) use ($hash) { $hash[$e->getRequest()] = $e; };
        $options = RequestEvents::convertEventArray($options, $events, [
            'priority' => RequestEvents::LATE,
            'fn'       => $fn
        ]);

        (new Pool($client, $requests, $options))->deref();

        // Update the received value for any of the intercepted requests.
        $result = [];
        foreach ($hash as $request) {
            if ($hash[$request] instanceof CompleteEvent) {
                $result[] = $hash[$request]->getResponse();
            } elseif ($hash[$request] instanceof ErrorEvent) {
                $result[] = $hash[$request]->getException();
            }
        }

        return $result;
    }

    /**
     * Gets a value from an array using a path syntax to retrieve nested data.
     *
     * This method does not allow for keys that contain "/". You must traverse
     * the array manually or using something more advanced like JMESPath to
     * work with keys that contain "/".
     *
     *     // Get the bar key of a set of nested arrays.
     *     // This is equivalent to $collection['foo']['baz']['bar'] but won't
     *     // throw warnings for missing keys.
     *     GuzzleHttp\get_path($data, 'foo/baz/bar');
     *
     * @param array  $data Data to retrieve values from
     * @param string $path Path to traverse and retrieve a value from
     *
     * @return mixed|null
     */
    public static function getPath($data, $path)
    {
        $path = explode('/', $path);

        while (null !== ($part = array_shift($path))) {
            if (!is_array($data) || !isset($data[$part])) {
                return null;
            }
            $data = $data[$part];
        }

        return $data;
    }

    /**
     * Set a value in a nested array key. Keys will be created as needed to set
     * the value.
     *
     * This function does not support keys that contain "/" or "[]" characters
     * because these are special tokens used when traversing the data structure.
     * A value may be prepended to an existing array by using "[]" as the final
     * key of a path.
     *
     *     GuzzleHttp\get_path($data, 'foo/baz'); // null
     *     GuzzleHttp\set_path($data, 'foo/baz/[]', 'a');
     *     GuzzleHttp\set_path($data, 'foo/baz/[]', 'b');
     *     GuzzleHttp\get_path($data, 'foo/baz');
     *     // Returns ['a', 'b']
     *
     * @param array  $data  Data to modify by reference
     * @param string $path  Path to set
     * @param mixed  $value Value to set at the key
     *
     * @throws \RuntimeException when trying to setPath using a nested path
     *     that travels through a scalar value.
     */
    public static function setPath(&$data, $path, $value)
    {
        $current =& $data;
        $queue = explode('/', $path);
        while (null !== ($key = array_shift($queue))) {
            if (!is_array($current)) {
                throw new \RuntimeException("Trying to setPath {$path}, but "
                    . "{$key} is set and is not an array");
            } elseif (!$queue) {
                if ($key == '[]') {
                    $current[] = $value;
                } else {
                    $current[$key] = $value;
                }
            } elseif (isset($current[$key])) {
                $current =& $current[$key];
            } else {
                $current[$key] = [];
                $current =& $current[$key];
            }
        }
    }

    /**
     * Expands a URI template
     *
     * @param string $template  URI template
     * @param array  $variables Template variables
     *
     * @return string
     */
    public static function uriTemplate($template, array $variables)
    {
        if (function_exists('\\uri_template')) {
            return uri_template($template, $variables);
        }

        static $uriTemplate;
        if (!$uriTemplate) {
            $uriTemplate = new UriTemplate();
        }

        return $uriTemplate->expand($template, $variables);
    }

    /**
     * Wrapper for JSON decode that implements error detection with helpful
     * error messages.
     *
     * @param string $json    JSON data to parse
     * @param bool $assoc     When true, returned objects will be converted
     *                        into associative arrays.
     * @param int    $depth   User specified recursion depth.
     * @param int    $options Bitmask of JSON decode options.
     *
     * @return mixed
     * @throws \InvalidArgumentException if the JSON cannot be parsed.
     * @link http://www.php.net/manual/en/function.json-decode.php
     */
    public static function jsonDecode($json, $assoc = false, $depth = 512, $options = 0)
    {
        static $jsonErrors = [
            JSON_ERROR_DEPTH => 'JSON_ERROR_DEPTH - Maximum stack depth exceeded',
            JSON_ERROR_STATE_MISMATCH => 'JSON_ERROR_STATE_MISMATCH - Underflow or the modes mismatch',
            JSON_ERROR_CTRL_CHAR => 'JSON_ERROR_CTRL_CHAR - Unexpected control character found',
            JSON_ERROR_SYNTAX => 'JSON_ERROR_SYNTAX - Syntax error, malformed JSON',
            JSON_ERROR_UTF8 => 'JSON_ERROR_UTF8 - Malformed UTF-8 characters, possibly incorrectly encoded'
        ];

        $data = \json_decode($json, $assoc, $depth, $options);

        if (JSON_ERROR_NONE !== json_last_error()) {
            $last = json_last_error();
            throw new \InvalidArgumentException(
                'Unable to parse JSON data: '
                . (isset($jsonErrors[$last])
                    ? $jsonErrors[$last]
                    : 'Unknown error')
            );
        }

        return $data;
    }
}
