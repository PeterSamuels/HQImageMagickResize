<?php
/**
 * Handler for JPEG images.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Media
 */
namespace MediaWiki\Extension\HQImageMagickResize;
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
class HQJpegHandler extends \JpegHandler {
	/**
	 * @inheritDoc
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
		// $decoderHint = [];
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

		// JPEG decoder hint to reduce memory, available since IM 6.5.6-2
		// $decoderHint = [ '-define', "jpeg:size={$params['physicalDimensions']}" ];
		// Decoder hint commented out due to aliasing problems with scanned printer dots

		if ( $jpegPixelFormat ) {
			$factors = $this->imageMagickSubsampling( $jpegPixelFormat );
			$subsampling = [ '-sampling-factor', implode( ',', $factors ) ];
		}

		// Use one thread only, to avoid deadlock bugs on OOM
		$env = [ 'OMP_NUM_THREADS' => 1 ];
		if ( (string)$imageMagickTempDir !== '' ) {
			$env['MAGICK_TMPDIR'] = $imageMagickTempDir;
		}

		$rotation = isset( $params['disableRotation'] ) ? 0 : $this->getRotation( $image );
		[ $width, $height ] = $this->extractPreRotationDimensions( $params, $rotation );

		$cmd = Shell::escape( ...array_merge(
			[ $imageMagickConvertCommand ],
			$quality,
			// Specify white background color, will be used for transparent images
			// in Internet Explorer/Windows instead of default black.
			[ '-background', 'white' ],
			// $decoderHint,
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
			$subsampling,
			$animation_post,
			[ $this->escapeMagickOutput( $params['dstPath'] ) ] ) );

		wfDebug( __METHOD__ . ": running ImageMagick: $cmd" );
		$retval = 0;
		$err = wfShellExecWithStderr( $cmd, $retval, $env );

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

		return false;
	}
}
