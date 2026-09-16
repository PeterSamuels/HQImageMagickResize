<?php
/**
 * Generic handler for bitmap images.
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Media
 */

/**
  * Code originally from MediaWiki's BitmapHandler and JpegHandler
  * Changes made by Peter Samuels on September 15, 2026:
  *     Changed -thumbnail to -strip -resize
  *     Removed code for non-JPEG MIME types
  *     Removed decoder hint
*/

namespace MediaWiki\Extension\HQImageMagickResize;
use MediaWiki\Media\JpegHandler;
use MediaWiki\FileRepo\File\File;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

/**
 * JPEG specific handler.
 * Inherits most stuff from BitmapHandler, just here to do the metadata handler differently.
 *
 * Metadata stuff common to Jpeg and built-in Tiff (not PagedTiffHandler) is
 * in ExifBitmapHandler.
 *
 * @ingroup Media
 */
class HQJpegHandler extends JpegHandler {
	/**
	 * Transform an image using ImageMagick
	 * @stable to override
	 *
	 * @param File $image File associated with this thumbnail
	 * @param array $params Array with scaler params
	 *
	 * @return MediaTransformError|false Error object if error occurred, false (=no error) otherwise
	 */
	protected function transformImageMagick( $image, $params ) {
		$useTinyRGBForJPGThumbnails = MediaWikiServices::getInstance()
			->getMainConfig()->get( MainConfigNames::UseTinyRGBForJPGThumbnails );

		# use ImageMagick
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$sharpenReductionThreshold = $mainConfig->get( MainConfigNames::SharpenReductionThreshold );
		$sharpenParameter = $mainConfig->get( MainConfigNames::SharpenParameter );
		$imageMagickTempDir = $mainConfig->get( MainConfigNames::ImageMagickTempDir );
		$imageMagickConvertCommand = $mainConfig->get( MainConfigNames::ImageMagickConvertCommand );
		$jpegPixelFormat = $mainConfig->get( MainConfigNames::JpegPixelFormat );
		$jpegQuality = $mainConfig->get( MainConfigNames::JpegQuality );
		$quality = [];
		$sharpen = [];
		$scene = false;
		$animation_post = [];
		$subsampling = [];

		//Removed code for other MIME types, since this is a JPEG handler only.
		$qualityVal = isset( $params['quality'] ) ? (string)$params['quality'] : null;
		$quality = [ '-quality', $qualityVal ?: (string)$jpegQuality ]; // 80% by default
		if ( $params['interlace'] ) {
			$animation_post = [ '-interlace', 'JPEG' ];
		}
		# Sharpening, see T8193
		if ( ( $params['physicalWidth'] + $params['physicalHeight'] )
			/ ( $params['srcWidth'] + $params['srcHeight'] )
			< $sharpenReductionThreshold
		) {
			$sharpen = [ '-sharpen', $sharpenParameter ];
		}

		// Decoder hint removed due to aliasing problems with scanned printer dots

		if ( $jpegPixelFormat ) {
			$factors = $this->imageMagickSubsampling( $jpegPixelFormat );
			$subsampling = [ '-sampling-factor', implode( ',', $factors ) ];
		}

		// Use one thread only, to avoid deadlock bugs on OOM
		$env = [ 'OMP_NUM_THREADS' => '1' ];
		if ( (string)$imageMagickTempDir !== '' ) {
			$env['MAGICK_TMPDIR'] = (string)$imageMagickTempDir;
		}

		$rotation = isset( $params['disableRotation'] ) ? 0 : $this->getRotation( $image );
		[ $width, $height ] = $this->extractPreRotationDimensions( $params, $rotation );
		$mirroring = isset( $params['disableRotation'] ) ? null : $this->getMirrored( $image );
		$mirrored = match ( $mirroring ) {
			'horizontal' => [ '-flop' ],
			'vertical' => [ '-flip' ],
			default => [],
		};

		$cmd = Shell::escape( ...array_merge(
			[ $imageMagickConvertCommand ],
			$quality,
			// Specify white background color, will be used for transparent images
			// in Internet Explorer/Windows instead of default black.
			[ '-background', 'white' ],
			[ $this->escapeMagickInput( $params['srcPath'], $scene ) ],
			// For the -thumbnail option a "!" is needed to force exact size,
			// or ImageMagick may decide your ratio is wrong and slice off
			// a pixel.
			[ '-strip' ],
			[ '-resize', "{$width}x{$height}!" ],
			// Add the source url as a comment to the thumb, but don't add the flag if there's no comment
			( $params['comment'] !== ''
				? [ '-set', 'comment', $this->escapeMagickProperty( $params['comment'] ) ]
				: [] ),
			// T108616: Avoid exposure of local file path
			[ '+set', 'Thumb::URI' ],
			[ '-depth', 8 ],
			$sharpen,
			[ '-rotate', "-$rotation" ],
			$mirrored,
			$subsampling,
			$animation_post,
			[ $this->escapeMagickOutput( $params['dstPath'] ) ] ) );

		wfDebug( __METHOD__ . ": running ImageMagick: $cmd" );
		$shell = Shell::command()->unsafeCommand( $cmd )->environment( $env )->execute();
		$retval = $shell->getExitCode();
		$err = $shell->getStderr();

		if ( $retval !== 0 ) {
			$this->logErrorForExternalProcess( $retval, $err, $cmd );

			return $this->getMediaTransformError( $params, "$err\nError code: $retval" );
		}

		if ( $useTinyRGBForJPGThumbnails ) {
			// T100976 If the profile embedded in the JPG is sRGB, swap it for the smaller
			// (and free) TinyRGB

			/**
			 * We'll want to replace the color profile for JPGs:
			 * * in the sRGB color space, or with the sRGB profile
			 *   (other profiles will be left untouched)
			 * * without color space or profile, in which case browsers
			 *   should assume sRGB, but don't always do (e.g. on wide-gamut
			 *   monitors (unless it's meant for low bandwidth)
			 * @see https://phabricator.wikimedia.org/T134498
			 */
			$colorSpaces = [ self::SRGB_EXIF_COLOR_SPACE, '-' ];
			$profiles = [ self::SRGB_ICC_PROFILE_DESCRIPTION ];

			// we'll also add TinyRGB profile to images lacking a profile, but
			// only if they're not low quality (which are meant to save bandwidth
			// and we don't want to increase the filesize by adding a profile)
			if ( isset( $params['quality'] ) && $params['quality'] > 30 ) {
				$profiles[] = '-';
			}

			$this->swapICCProfile(
				$params['dstPath'],
				$colorSpaces,
				$profiles,
				realpath( __DIR__ ) . '/tinyrgb.icc'
			);
		}

		return false; # No error
	}
}