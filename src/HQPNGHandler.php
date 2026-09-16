<?php
/**
 * Generic handler for bitmap images.
 *
 * @license GPL-2.0-or-later
 * @file
 * @ingroup Media
 */

/**
  * Code originally from MediaWiki's BitmapHandler
  * Changes made by Peter Samuels on September 13, 2026:
  *     Changed -thumbnail to -strip -resize
  *     Removed code for non-PNG MIME types
*/
namespace MediaWiki\Extension\HQImageMagickResize;
use MediaWiki\Media\PNGHandler;
use MediaWiki\FileRepo\File\File;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Shell\Shell;

/**
 * Handler for PNG images.
 *
 * @ingroup Media
 */
class HQPNGHandler extends PNGHandler {
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
		# use ImageMagick
		$mainConfig = MediaWikiServices::getInstance()->getMainConfig();
		$imageMagickTempDir = $mainConfig->get( MainConfigNames::ImageMagickTempDir );
		$imageMagickConvertCommand = $mainConfig->get( MainConfigNames::ImageMagickConvertCommand );
		$quality = [];
		$scene = false;
		$animation_post = [];

		//Removed code for other MIME types, since this is a PNG handler only.
		$quality = [ '-quality', '95' ]; // zlib 9, adaptive filtering
		if ( $params['interlace'] ) {
			$animation_post = [ '-interlace', 'PNG' ];
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
			[ '-rotate', "-$rotation" ],
			$mirrored,
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

		return false; # No error
	}
}