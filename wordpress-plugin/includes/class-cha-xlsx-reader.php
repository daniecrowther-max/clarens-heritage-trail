<?php
/**
 * Minimal .xlsx sheet reader — ZipArchive + SimpleXML only, both standard
 * on the Elitehost cPanel stack. No Composer dependencies.
 *
 * Reads one worksheet into an array of rows (arrays of trimmed strings).
 * Enough for the interns' capture template; not a general xlsx library.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Xlsx_Reader {

	const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

	/**
	 * Read a named worksheet.
	 *
	 * @param string $path       Path to the .xlsx file.
	 * @param string $sheet_name Worksheet name (case-insensitive).
	 * @return array[]|WP_Error Rows as zero-indexed string arrays.
	 */
	public static function read( $path, $sheet_name ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'cha_no_zip', __( 'ZipArchive is not available on this server.', 'cha' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'cha_bad_file', __( 'Could not open the file as an .xlsx workbook.', 'cha' ) );
		}

		$workbook = self::xml( $zip, 'xl/workbook.xml' );
		$rels     = self::xml( $zip, 'xl/_rels/workbook.xml.rels' );
		if ( ! $workbook || ! $rels ) {
			$zip->close();
			return new WP_Error( 'cha_bad_file', __( 'Not a valid .xlsx workbook.', 'cha' ) );
		}

		// Relationship id → worksheet part path.
		$targets = array();
		foreach ( $rels->Relationship as $rel ) {
			$target = ltrim( (string) $rel['Target'], '/' );
			if ( 0 !== strpos( $target, 'xl/' ) ) {
				$target = 'xl/' . $target;
			}
			$targets[ (string) $rel['Id'] ] = $target;
		}

		// Find the requested sheet by name.
		$sheet_path = null;
		foreach ( $workbook->sheets->sheet as $sheet ) {
			if ( 0 === strcasecmp( (string) $sheet['name'], $sheet_name ) ) {
				$rid        = (string) $sheet->attributes( self::REL_NS )->id;
				$sheet_path = isset( $targets[ $rid ] ) ? $targets[ $rid ] : null;
				break;
			}
		}
		if ( null === $sheet_path ) {
			$zip->close();
			return new WP_Error(
				'cha_no_sheet',
				sprintf(
					/* translators: %s: worksheet name */
					__( 'Worksheet "%s" not found in the workbook.', 'cha' ),
					$sheet_name
				)
			);
		}

		$shared = self::shared_strings( $zip );
		$xml    = self::xml( $zip, $sheet_path );
		$zip->close();

		if ( ! $xml ) {
			return new WP_Error( 'cha_bad_file', __( 'Could not read the worksheet.', 'cha' ) );
		}

		$rows = array();
		foreach ( $xml->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$idx           = self::col_index( (string) $c['r'] );
				$cells[ $idx ] = self::cell_value( $c, $shared );
			}
			if ( empty( $cells ) ) {
				$rows[] = array();
				continue;
			}
			$width  = max( array_keys( $cells ) ) + 1;
			$padded = array();
			for ( $i = 0; $i < $width; $i++ ) {
				$padded[ $i ] = isset( $cells[ $i ] ) ? $cells[ $i ] : '';
			}
			$rows[] = $padded;
		}

		return $rows;
	}

	/**
	 * Load an XML part from the archive.
	 *
	 * @param ZipArchive $zip  Open archive.
	 * @param string     $part Part path.
	 * @return SimpleXMLElement|false
	 */
	private static function xml( $zip, $part ) {
		$raw = $zip->getFromName( $part );
		if ( false === $raw ) {
			return false;
		}
		return simplexml_load_string( $raw );
	}

	/**
	 * The shared-strings table (handles plain and rich-text runs).
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return string[]
	 */
	private static function shared_strings( $zip ) {
		$strings = array();
		$xml     = self::xml( $zip, 'xl/sharedStrings.xml' );
		if ( ! $xml ) {
			return $strings;
		}
		foreach ( $xml->si as $si ) {
			$text = '';
			foreach ( $si->xpath( './/*[local-name()="t"]' ) as $t ) {
				$text .= (string) $t;
			}
			$strings[] = $text;
		}
		return $strings;
	}

	/**
	 * One cell's value as a trimmed string.
	 *
	 * @param SimpleXMLElement $c      Cell node.
	 * @param string[]         $shared Shared-strings table.
	 * @return string
	 */
	private static function cell_value( $c, $shared ) {
		$type = (string) $c['t'];

		if ( 's' === $type ) {
			$i     = (int) $c->v;
			$value = isset( $shared[ $i ] ) ? $shared[ $i ] : '';
		} elseif ( 'inlineStr' === $type ) {
			$value = '';
			foreach ( $c->is->xpath( './/*[local-name()="t"]' ) as $t ) {
				$value .= (string) $t;
			}
		} else {
			$value = (string) $c->v;
		}

		return trim( $value );
	}

	/**
	 * Column letters of a cell reference ("C4") to a zero-based index.
	 *
	 * @param string $ref Cell reference.
	 * @return int
	 */
	private static function col_index( $ref ) {
		$n = 0;
		foreach ( str_split( $ref ) as $ch ) {
			if ( $ch < 'A' || $ch > 'Z' ) {
				break;
			}
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return $n - 1;
	}
}
