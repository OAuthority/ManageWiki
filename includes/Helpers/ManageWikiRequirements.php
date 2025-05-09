<?php

namespace Miraheze\ManageWiki\Helpers;

use MediaWiki\Context\RequestContext;
use MediaWiki\MediaWikiServices;
use MediaWiki\SiteStats\SiteStats;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;

/**
 * Helper class for de-centralising requirement checking
 */
class ManageWikiRequirements {

	/**
	 * Main class for evaluating whether requirements are met, and at what level
	 *
	 * @param array $actions Requirements that need to be met
	 * @param array $extensionList Enabled extensions on the wiki
	 * @param bool $ignorePerms Whether a permissions check should be carried out
	 * @param RemoteWikiFactory $remoteWiki
	 * @return bool Whether the extension can be enabled
	 */
	public static function process( array $actions, array $extensionList, bool $ignorePerms, RemoteWikiFactory $remoteWiki ) {
		// Produces an array of steps and results (so we can fail what we can't do but apply what works)
		$stepResponse = [];

		foreach ( $actions as $action => $data ) {
			switch ( $action ) {
				case 'permissions':
					// We don't check permissions if we are in CLI mode, so that we can toggle restricted extensions in CLI
					$stepResponse['permissions'] = ( $ignorePerms || PHP_SAPI === 'cli' ) ? true : self::permissions( $data );
					break;
				case 'extensions':
					$stepResponse['extensions'] = self::extensions( $data, $extensionList );
					break;
				case 'activeusers':
					$stepResponse['activeusers'] = self::activeUsers( $data );
					break;
				case 'articles':
					$stepResponse['articles'] = self::articles( $data );
					break;
				case 'pages':
					$stepResponse['pages'] = self::pages( $data );
					break;
				case 'images':
					$stepResponse['images'] = self::images( $data );
					break;
				case 'settings':
					$stepResponse['settings'] = self::settings( $data );
					break;
				case 'visibility':
					$stepResponse['visibility'] = self::visibility( $data, $remoteWiki );
					break;
				default:
					return false;
			}
		}

		return !(bool)array_search( false, $stepResponse );
	}

	/**
	 * @param array $data Array of permissions needed
	 * @return bool Whether permissions requirements are met
	 */
	private static function permissions( array $data ) {
		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		foreach ( $data as $perm ) {
			if ( !$permissionManager->userHasRight( RequestContext::getMain()->getUser(), $perm ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array $data Array of extensions needed
	 * @param array $extensionList Extensions already enabled on the wiki
	 * @return bool Whether extension requirements are met
	 */
	private static function extensions( array $data, array $extensionList ) {
		foreach ( $data as $extension ) {
			if ( is_array( $extension ) ) {
				$count = 0;
				foreach ( $extension as $or ) {
					if ( in_array( $or, $extensionList ) ) {
						$count++;
					}
				}

				if ( !$count ) {
					return false;
				}
			} elseif ( !in_array( $extension, $extensionList ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param int $lim Cut off number
	 * @return bool Whether limit is exceeded or not
	 */
	private static function activeUsers( int $lim ) {
		return ( SiteStats::activeUsers() <= $lim );
	}

	/**
	 * @param int $lim Cut off number
	 * @return bool Whether limit is exceeded or not
	 */
	private static function articles( int $lim ) {
		return ( SiteStats::articles() <= $lim );
	}

	/**
	 * @param int $lim Cut off number
	 * @return bool Whether limit is exceeded or not
	 */
	private static function pages( int $lim ) {
		return ( SiteStats::pages() <= $lim );
	}

	/**
	 * @param int $lim Cut off number
	 * @return bool Whether limit is exceeded or not
	 */
	private static function images( int $lim ) {
		return ( SiteStats::images() <= $lim );
	}

	/**
	 * @param array $data
	 * @return bool
	 */
	private static function settings( array $data ) {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		$database = $data['dbname'] ?? $config->get( 'DBname' );
		$setting = $data['setting'];
		$value = $data['value'];

		$manageWikiSettings = new ManageWikiSettings( $database );

		$wikiValue = $manageWikiSettings->list( $setting );

		if ( $wikiValue !== null ) {
			// We need to cast $wikiValue to an array
			// to convert any values (boolean) to an array.
			// Otherwise TypeError is thrown.
			if ( $wikiValue === $value || in_array( $value, (array)$wikiValue ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array $data
	 * @param RemoteWikiFactory $remoteWiki
	 * @return bool
	 */
	private static function visibility( array $data, RemoteWikiFactory $remoteWiki ) {
		$ret = [];

		foreach ( $data as $key => $val ) {
			if ( $key === 'state' ) {
				$ret['state'] = ( ( $val === 'private' && $remoteWiki->isPrivate() ) || ( $val === 'public' && !$remoteWiki->isPrivate() ) );
			} elseif ( $key === 'permissions' ) {
				$ret['permissions'] = (bool)( self::permissions( $val ) );
			}
		}

		return !(bool)array_search( false, $ret );
	}
}
