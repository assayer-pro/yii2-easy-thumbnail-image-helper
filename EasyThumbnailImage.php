<?php
/**
 * @link https://github.com/assayer-pro/yii2-easy-thumbnail-image-helper
 * @copyright 2018 Assayer Pro
 * @copyright Copyright (c) 2014-2017 HimikLab
 * @license http://opensource.org/licenses/MIT MIT
 */

namespace assayerpro\thumbnail;

use Imagine\Image\ManipulatorInterface;
use Yii;
use yii\base\InvalidConfigException;
use yii\helpers\FileHelper;
use yii\helpers\Html;
use yii\imagine\Image;
use yii\httpclient\Client;

/**
 * Yii2 helper for creating and caching thumbnails on real time
 * @author Serge Larin <serge.larin@gmail.com>
 * @author HimikLab
 * @package assayerpro\thumbnail
 */
class EasyThumbnailImage extends yii\base\Component
{
    const THUMBNAIL_OUTBOUND = ManipulatorInterface::THUMBNAIL_OUTBOUND;
    const THUMBNAIL_INSET = ManipulatorInterface::THUMBNAIL_INSET;
    const MKDIR_MODE = 0755;

    const CHECK_REM_MODE_NONE = 1;
    const CHECK_REM_MODE_CRC = 2;
    const CHECK_REM_MODE_HEADER = 3;

    /** @var string $cacheAlias path alias relative with @web where the cache files are kept */
    public $cacheAlias = 'assets/thumbnails';

    /** @var int $cacheExpire */
    public $cacheExpire = 0;

    /**
     * @var int $quality
     * @access public
     */
    public $quality = 75;
    /**
     * Creates and caches the image thumbnail and returns ImageInterface.
     *
     * @param string $filename the image file path or path alias or URL
     * @param integer $width the width in pixels to create the thumbnail
     * @param integer $height the height in pixels to create the thumbnail
     * @param string $mode self::THUMBNAIL_INSET, the original image
     * @param integer $quality
     * @param integer $checkRemFileMode
     * is scaled down so it is fully contained within the thumbnail dimensions.
     * The specified $width and $height (supplied via $size) will be considered
     * maximum limits. Unless the given dimensions are equal to the original image’s
     * aspect ratio, one dimension in the resulting thumbnail will be smaller than
     * the given limit. If self::THUMBNAIL_OUTBOUND mode is chosen, then
     * the thumbnail is scaled so that its smallest side equals the length of the
     * corresponding side in the original image. Any excess outside of the scaled
     * thumbnail’s area will be cropped, and the returned thumbnail will have
     * the exact $width and $height specified
     * @throws \Imagine\Exception\RuntimeException
     * @throws \Imagine\Exception\InvalidArgumentException
     * @throws FileNotFoundException
     * @return \Imagine\Image\ImageInterface
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    public function thumbnail(
        $filename,
        $width,
        $height,
        $mode = self::THUMBNAIL_OUTBOUND,
        $quality = null,
        $checkRemFileMode = self::CHECK_REM_MODE_NONE
    ) {

        return Image::getImagine()
            ->open($this->thumbnailFile($filename, $width, $height, $mode, $quality, $checkRemFileMode));
    }

    /**
     * Creates and caches the image thumbnail and returns full path from thumbnail file.
     *
     * @param string $filename the image file path or path alias or URL
     * @param integer $width the width in pixels to create the thumbnail
     * @param integer $height the height in pixels to create the thumbnail
     * @param string $mode self::THUMBNAIL_INSET, the original image
     * @param integer $quality
     * @param integer $checkRemFileMode
     * @return string
     * @throws FileNotFoundException
     * @throws \Imagine\Exception\InvalidArgumentException
     * @throws \Imagine\Exception\RuntimeException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    public function thumbnailFile(
        $filename,
        $width,
        $height,
        $mode = self::THUMBNAIL_OUTBOUND,
        $quality = null,
        $checkRemFileMode = self::CHECK_REM_MODE_NONE
    ) {

        $fileContent = null;
        $fileNameIsUrl = false;
        if (preg_match('/^https?:\/\//', $filename)) {
            $fileNameIsUrl = true;
            switch ($checkRemFileMode) {
                case self::CHECK_REM_MODE_NONE:
                    $thumbnailFileName = md5($filename . $width . $height . $mode);
                    break;
                case self::CHECK_REM_MODE_CRC:
                    $fileContent = self::fileFromUrlContent($filename);
                    $thumbnailFileName = md5($filename . $width . $height . $mode . crc32($fileContent));
                    break;
                case self::CHECK_REM_MODE_HEADER:
                    $thumbnailFileName = md5($filename . $width . $height . $mode . self::fileFromUrlDate($filename));
                    break;
                default:
                    throw new InvalidConfigException();
            }
        } else {
            $filename = FileHelper::normalizePath(Yii::getAlias($filename));
            if (!is_file($filename)) {
                throw new FileNotFoundException("File {$filename} doesn't exist");
            }
            $thumbnailFileName = md5($filename . $width . $height . $mode . filemtime($filename));
        }
        $cachePath = Yii::getAlias('@webroot/' . $this->cacheAlias);

        $thumbnailFileExt = strrchr($filename, '.');
        $thumbnailFilePath = $cachePath . DIRECTORY_SEPARATOR . substr($thumbnailFileName, 0, 2);
        $thumbnailFile = $thumbnailFilePath . DIRECTORY_SEPARATOR . $thumbnailFileName . $thumbnailFileExt;

        if (file_exists($thumbnailFile)) {
            if ($this->cacheExpired($thumbnailFile)) {
                unlink($thumbnailFile);
            } else {
                return $thumbnailFile;
            }
        }
        if (!is_dir($thumbnailFilePath)) {
            mkdir($thumbnailFilePath, self::MKDIR_MODE, true);
        }

        if ($fileNameIsUrl) {
            $image = Image::getImagine()->load($fileContent ?: self::fileFromUrlContent($filename));
        } else {
            $image = Image::getImagine()->open($filename);
        }

        $options = [
            'quality' => $quality === null ? $this->quality : $quality
        ];

        $image = Image::thumbnail($image, $width, $height)->save($thumbnailFile, $options);
        return $thumbnailFile;
    }

    /**
     * Creates and caches the image thumbnail and returns URL from thumbnail file.
     *
     * @param string $filename the image file path or path alias or URL
     * @param integer $width the width in pixels to create the thumbnail
     * @param integer $height the height in pixels to create the thumbnail
     * @param string $mode self::THUMBNAIL_INSET, the original image
     * @param integer $quality
     * @param integer $checkRemFileMode
     * @return string
     * @throws FileNotFoundException
     * @throws \Imagine\Exception\InvalidArgumentException
     * @throws \Imagine\Exception\RuntimeException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    public function thumbnailFileUrl(
        $filename,
        $width,
        $height,
        $mode = self::THUMBNAIL_OUTBOUND,
        $quality = null,
        $checkRemFileMode = self::CHECK_REM_MODE_NONE
    ) {

        $cacheUrl = Yii::getAlias('@web/' . $this->cacheAlias);
        try {
            $thumbnailFilePath = $this->thumbnailFile($filename, $width, $height, $mode, $quality, $checkRemFileMode);
        } catch (\Exception $e) {
            return static::errorHandler($e, $filename);
        }

        preg_match('#[^\\' . DIRECTORY_SEPARATOR . ']+$#', $thumbnailFilePath, $matches);
        $fileName = $matches[0];

        return $cacheUrl . '/' . substr($fileName, 0, 2) . '/' . $fileName;
    }

    /**
     * Creates and caches the image thumbnail and returns <img> tag.
     *
     * @param string $filename the image file path or path alias or URL
     * @param integer $width the width in pixels to create the thumbnail
     * @param integer $height the height in pixels to create the thumbnail
     * @param string $mode self::THUMBNAIL_INSET, the original image
     * @param array $options options similarly with \yii\helpers\Html::img()
     * @param integer $quality
     * @param integer $checkRemFileMode
     * @return string
     * @throws FileNotFoundException
     * @throws \Imagine\Exception\InvalidArgumentException
     * @throws \Imagine\Exception\RuntimeException
     * @throws \yii\base\InvalidParamException
     * @throws \yii\base\InvalidConfigException
     */
    public function thumbnailImg(
        $filename,
        $width,
        $height,
        $mode = self::THUMBNAIL_OUTBOUND,
        $options = [],
        $quality = null,
        $checkRemFileMode = self::CHECK_REM_MODE_NONE
    ) {

        $thumbnailFileUrl = $this->thumbnailFileUrl($filename, $width, $height, $mode, $quality, $checkRemFileMode);

        return Html::img(
            $thumbnailFileUrl,
            $options
        );
    }

    /**
     * Clear cache directory.
     *
     * @throws \yii\base\InvalidParamException
     * @return bool
     */
    public function clearCache()
    {
        $cacheDir = Yii::getAlias('@webroot/' . $this->cacheAlias);
        FileHelper::removeDirectory($cacheDir);
        return @mkdir($cacheDir, self::MKDIR_MODE, true);
    }

    /**
     * @param \Exception $error
     * @param string $filename
     * @return string
     */
    protected static function errorHandler($error, $filename)
    {
        if ($error instanceof FileNotFoundException) {
            return $error->getMessage();
        }

        Yii::warning("{$error->getCode()}\n{$error->getMessage()}\n{$error->getFile()}");
        return 'Error ' . $error->getCode();
    }

    /**
     * cacheExpired
     *
     * @param string $thumbnailFile
     * @access protected
     * @return boolean
     */
    protected function cacheExpired($thumbnailFile)
    {
        return $this->cacheExpire !== 0 && (time() - filemtime($thumbnailFile)) > $this->$cacheExpire;
    }

    /**
     * @param string $url
     * @return string
     * @throws FileNotFoundException
     */
    protected static function fileFromUrlDate($url)
    {
        $client = new Client();
        $response = $client->head($url)->send();
        if (!$response->isOk) {
               throw new FileNotFoundException("URL {$url} doesn't exist");
        }

        return $response->headers['last-modified'];
    }

    /**
     * @param string $url
     * @return string
     * @throws FileNotFoundException
     */
    protected static function fileFromUrlContent($url)
    {
        $client = new Client();
        $response = $client->get($url)->send();
        if (!$response->isOk) {
               throw new FileNotFoundException("URL {$url} doesn't exist");
        }
        return $response->content;
    }
}
