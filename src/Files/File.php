<?php namespace Nylas\Files;

use Nylas\Utilities\API;
use Nylas\Utilities\Helper;
use Nylas\Utilities\Options;
use Nylas\Utilities\Validate as V;
use Psr\Http\Message\StreamInterface;

/**
 * ----------------------------------------------------------------------------------
 * Nylas Files
 * ----------------------------------------------------------------------------------
 *
 * @author lanlin
 * @change 2018/11/23
 */
class File
{

    // ------------------------------------------------------------------------------

    /**
     * @var \Nylas\Utilities\Options
     */
    private $options;

    // ------------------------------------------------------------------------------

    /**
     * File constructor.
     *
     * @param \Nylas\Utilities\Options $options
     */
    public function __construct(Options $options)
    {
        $this->options = $options;
    }

    // ------------------------------------------------------------------------------

    /**
     * get files list
     *
     * @param array $params
     * @return array
     */
    public function getFilesList(array $params = [])
    {
        $params['access_token'] =
        $params['access_token'] ?? $this->options->getAccessToken();

        $rule = V::keySet(
            V::keyOptional('view', V::in(['count', 'ids'])),
            V::keyOptional('filename', V::stringType()->notEmpty()),
            V::keyOptional('message_id', V::stringType()->notEmpty()),
            V::keyOptional('content_type', V::stringType()->notEmpty()),

            V::key('access_token', V::stringType()->notEmpty())
        );

        V::doValidate($rule, $params);

        $header = ['Authorization' => $params['access_token']];

        unset($params['access_token']);

        return $this->options
        ->getSync()
        ->setQuery($params)
        ->setHeaderParams($header)
        ->get(API::LIST['files']);
    }

    // ------------------------------------------------------------------------------

    /**
     * get file infos (not download file)
     *
     * @param string $fileId
     * @param string $accessToken
     * @return array
     */
    public function getFileInfo(string $fileId, string $accessToken = null)
    {
        $params =
        [
            'id'           => $fileId,
            'access_token' => $accessToken ?? $this->options->getAccessToken(),
        ];

        $rule = V::keySet(
            V::key('id', V::stringType()->notEmpty()),
            V::key('access_token', V::stringType()->notEmpty())
        );

        V::doValidate($rule, $params);

        $header = ['Authorization' => $params['access_token']];

        return $this->options
        ->getSync()
        ->setPath($params['id'])
        ->setHeaderParams($header)
        ->get(API::LIST['oneFile']);
    }

    // ------------------------------------------------------------------------------

    /**
     * delete file
     *
     * @param string $fileId
     * @param string $accessToken
     * @return void
     */
    public function deleteFile(string $fileId, string $accessToken = null)
    {
        $params =
        [
            'id'           => $fileId,
            'access_token' => $accessToken ?? $this->options->getAccessToken(),
        ];

        $rule = V::keySet(
            V::key('id', V::stringType()->notEmpty()),
            V::key('access_token', V::stringType()->notEmpty())
        );

        V::doValidate($rule, $params);

        $header = ['Authorization' => $params['access_token']];

        $this->options
        ->getSync()
        ->setPath($params['id'])
        ->setHeaderParams($header)
        ->delete(API::LIST['oneFile']);
    }

    // ------------------------------------------------------------------------------

    /**
     * upload file (support multiple upload)
     *
     * @param array $file
     * @param string $accessToken
     * @return array
     */
    public function uploadFile(array $file, string $accessToken = null)
    {
        $fileUploads = Helper::arrayToMulti($file);
        $accessToken = $accessToken ?? $this->options->getAccessToken();

        V::doValidate($this->multipartRules(), $fileUploads);
        V::doValidate(V::stringType()->notEmpty(), $accessToken);

        $upload = [];
        $target = API::LIST['files'];
        $header = ['Authorization' => $accessToken];

        foreach ($fileUploads as $item)
        {
            $item['name'] = 'file';

            $request = $this->options
            ->getAsync()
            ->setFormFiles($item)
            ->setHeaderParams($header);

            $upload[] = function () use ($request, $target)
            {
                return $request->post($target);
            };
        }

        $temp = $this->options->getAsync()->pool($upload);

        foreach ($temp as $key => $val)
        {
            $temp[$key] = Helper::isAssoc($val) ? $val : current($val);
        }

        return $temp;
    }

    // ------------------------------------------------------------------------------

    /**
     * download file (support multiple download)
     *
     * @param array $params
     * @param string $accessToken
     * @return array
     */
    public function downloadFile(array $params, string $accessToken = null)
    {
        $downloadArr = Helper::arrayToMulti($params);
        $accessToken = $accessToken ?? $this->options->getAccessToken();

        V::doValidate($this->downloadRules(), $downloadArr);
        V::doValidate(V::stringType()->notEmpty(), $accessToken);

        $method = [];
        $target = API::LIST['downloadFile'];
        $header = ['Authorization' => $accessToken];

        foreach ($downloadArr as $item)
        {
            $sink = $item['path'];

            $request = $this->options
            ->getAsync()
            ->setPath($item['id'])
            ->setHeaderParams($header);

            $method[] = function () use ($request, $target, $sink)
            {
                return $request->getSink($target, $sink);
            };
        }

        return $this->options->getAsync()->pool($method, true);
    }

    // ------------------------------------------------------------------------------

    /**
     * rules for download params
     *
     * @return \Respect\Validation\Validator
     */
    private function downloadRules()
    {
        $path = V::oneOf(
            V::resourceType(),
            V::stringType()->notEmpty(),
            V::instance(StreamInterface::class)
        );

        return  V::arrayType()->each(V::keySet(
            V::key('id', V::stringType()->notEmpty()),
            V::key('path', $path)
        ));
    }

    // ------------------------------------------------------------------------------

    /**
     * multipart upload rules
     *
     * @return \Respect\Validation\Validator
     */
    private function multipartRules()
    {
        return V::arrayType()->each(V::keyset(
            V::key('headers', V::arrayType(), false),
            V::key('filename', V::stringType()->length(1, null), false),

            V::key('contents', V::oneOf(
                V::resourceType(),
                V::stringType()->notEmpty(),
                V::instance(StreamInterface::class)
            ))
        ));
    }

    // ------------------------------------------------------------------------------

}
