<?php
/**
 * Handler for PNG images.
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
 * Handler for PNG images.
 *
 * @ingroup Media
 */
class HQPNGHandler extends \PNGHandler {
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
			$animation_post,
			[ $this->escapeMagickOutput( $params['dstPath'] ) ] ) );

		wfDebug( __METHOD__ . ": running ImageMagick: $cmd" );
		$retval = 0;
		$err = wfShellExecWithStderr( $cmd, $retval, $env );

		if ( $retval !== 0 ) {
			$this->logErrorForExternalProcess( $retval, $err, $cmd );

			return $this->getMediaTransformError( $params, "$err\nError code: $retval" );
		}

		return false; # No error
	}
}
