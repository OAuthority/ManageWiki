<?php

namespace Miraheze\ManageWiki\Specials;

use MediaWiki\Config\Config;
use MediaWiki\Context\RequestContext;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\SpecialPage\SpecialPage;
use Miraheze\CreateWiki\Services\CreateWikiDatabaseUtils;
use Miraheze\CreateWiki\Services\RemoteWikiFactory;
use Miraheze\ManageWiki\FormFactory\ManageWikiFormFactory;
use Miraheze\ManageWiki\Helpers\ManageWikiNamespaces;
use Miraheze\ManageWiki\Helpers\ManageWikiPermissions;
use Miraheze\ManageWiki\ManageWiki;
use OOUI\FieldLayout;
use OOUI\SearchInputWidget;

class SpecialManageWiki extends SpecialPage {

	private Config $config;
	private CreateWikiDatabaseUtils $databaseUtils;
	private RemoteWikiFactory $remoteWikiFactory;

	public function __construct() {
		parent::__construct( 'ManageWiki' );

		$this->config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'ManageWiki' );
		$this->databaseUtils = MediaWikiServices::getInstance()->get( 'CreateWikiDatabaseUtils' );
		$this->remoteWikiFactory = MediaWikiServices::getInstance()->get( 'RemoteWikiFactory' );
	}

	public function execute( $par ) {
		$par = explode( '/', $par ?? '', 3 );

		$out = $this->getOutput();
		$this->setHeaders();

		if ( $this->config->get( 'ManageWikiHelpUrl' ) ) {
			$out->addHelpLink( $this->config->get( 'ManageWikiHelpUrl' ), true );
		}

		if ( array_key_exists( $par[0], $this->config->get( 'ManageWiki' ) ) ) {
			$module = $par[0];
		} else {
			$module = 'core';
		}

		if ( !$this->getContext()->getUser()->isAllowed( 'managewiki-' . $module ) ) {
			$out->setPageTitle( $this->msg( 'managewiki-link-' . $module . '-view' )->text() );
			if ( $module !== 'permissions' || $module !== 'namespaces' ) {
				$out->addWikiMsg( "managewiki-header-{$module}-view" );
			}
		} else {
			$out->setPageTitle( $this->msg( 'managewiki-link-' . $module )->text() );
			if ( $module !== 'permissions' || $module !== 'namespaces' ) {
				$out->addWikiMsg( "managewiki-header-{$module}" );
			}
		}

		$additional = $par[1] ?? '';
		$filtered = $par[2] ?? $par[1] ?? '';

		if ( !ManageWiki::checkSetup( $module, true, $out ) ) {
			return false;
		}

		if ( $module === 'permissions' && $additional ) {
			$out->addSubtitle( $out->msg( 'editing' )->params( $additional ) );
		}

		$isCentralWiki = $this->databaseUtils->isCurrentWikiCentral();

		if ( !$isCentralWiki ) {
			$this->showWikiForm( $this->config->get( 'DBname' ), $module, $additional, $filtered );
		} elseif ( $par[0] == '' ) {
			$this->showInputBox();
		} elseif ( $module == 'core' ) {
			$dbName = $par[1] ?? $this->config->get( 'DBname' );
			$this->showWikiForm( strtolower( $dbName ), 'core', '', '' );
		} else {
			$this->showWikiForm( $this->config->get( 'DBname' ), $module, $additional, $filtered );
		}
	}

	public function getSubpagesForPrefixSearch() {
		return [
			'core',
			'extensions',
			'namespaces',
			'permissions',
			'settings',
		];
	}

	public function showInputBox() {
		$formDescriptor = [
			'info' => [
				'default' => $this->msg( 'managewiki-core-info' )->text(),
				'type' => 'info',
			],
			'dbname' => [
				'label-message' => 'managewiki-label-dbname',
				'type' => 'text',
				'size' => 20,
				'required' => true,
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $this->getContext(), 'searchForm' );
		$htmlForm->setWrapperLegendMsg( 'managewiki-core-header' );
		$htmlForm->setMethod( 'post' )
			->setSubmitCallback( [ $this, 'onSubmitRedirectToWikiForm' ] )
			->prepareForm()
			->show();

		return true;
	}

	public function onSubmitRedirectToWikiForm( array $params ) {
		if ( $params['dbname'] !== '' ) {
			header( 'Location: ' . SpecialPage::getTitleFor( 'ManageWiki', 'core' )->getFullURL() . '/' . $params['dbname'] );
		} else {
			return 'Invalid url.';
		}

		return true;
	}

	public function showWikiForm( $wiki, $module, $special, $filtered ) {
		$out = $this->getOutput();

		if ( $special !== '' || in_array( $module, [ 'core', 'extensions', 'settings' ] ) ) {
			$out->addModules( [ 'ext.managewiki.oouiform' ] );

			$out->addModuleStyles( [
				'ext.managewiki.oouiform.styles',
				'mediawiki.widgets.TagMultiselectWidget.styles',
			] );

			$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );
			$out->addModules( [ 'mediawiki.special.userrights' ] );
		}

		$remoteWiki = $this->remoteWikiFactory->newInstance( $wiki );

		if ( $remoteWiki->isLocked() ) {
			$out->addHTML( Html::errorBox( $this->msg( 'managewiki-mwlocked' )->escaped() ) );
		}

		$options = [];

		if ( $module != 'core' ) {
			if ( !$this->getContext()->getUser()->isAllowed( 'managewiki-' . $module ) ) {
				$out->addHTML(
					Html::errorBox( $this->msg( 'managewiki-error-nopermission' )->escaped() )
				);
			}
		} else {
			if ( !$this->getContext()->getUser()->isAllowed( 'managewiki-' . $module ) && !( $this->databaseUtils->isCurrentWikiCentral() ) ) {
				$out->addHTML(
					Html::errorBox( $this->msg( 'managewiki-error-nopermission' )->escaped() )
				);
			} elseif ( !$this->getContext()->getUser()->isAllowed( 'managewiki-' . $module ) ) {
				$out->addHTML(
					Html::errorBox( $this->msg( 'managewiki-error-nopermission-remote' )->escaped() )
				);
			}
		}

		if ( $module == 'permissions' && !$special ) {
			$language = RequestContext::getMain()->getLanguage();
			$mwPermissions = new ManageWikiPermissions( $wiki );
			$groups = array_keys( $mwPermissions->list() );

			foreach ( $groups as $group ) {
				$lowerCaseGroupName = strtolower( $group );
				$options[$language->getGroupName( $lowerCaseGroupName )] = $lowerCaseGroupName;
			}

			$this->reusableFormDescriptor( $module, $options );
		} elseif ( $module == 'namespaces' && $special == '' ) {
			$mwNamespaces = new ManageWikiNamespaces( $wiki );
			$namespaces = $mwNamespaces->list();

			foreach ( $namespaces as $id => $namespace ) {
				if ( $id % 2 ) {
					continue;
				}

				$options[$namespace['name']] = $id;
			}

			$this->reusableFormDescriptor( $module, $options );
		} else {
			$formFactory = new ManageWikiFormFactory();
			$htmlForm = $formFactory->getForm( $wiki, $remoteWiki, $this->getContext(), $this->config, $module, strtolower( $special ), $filtered );

			$out->addHTML( new FieldLayout(
				new SearchInputWidget( [
					'placeholder' => $this->msg( 'managewiki-search' )->text(),
				] ),
				[
					'classes' => [ 'managewiki-search' ],
					'label' => $this->msg( 'managewiki-search' )->text(),
					'invisibleLabel' => true,
					'infusable' => true,
				]
			) );

			$htmlForm->show();
		}
	}

	private function reusableFormDescriptor( string $module, array $options ) {
		$hidden = [];
		$selector = [];
		$create = [];

		$hidden['module'] = [
			'type' => 'hidden',
			'default' => $module
		];

		$selector['info'] = [
			'type' => 'info',
			'default' => $this->msg( "managewiki-{$module}-select-info" )->text(),
		];

		$selector['out'] = [
			'type' => 'select',
			'label-message' => "managewiki-{$module}-select",
			'options' => $options
		];

		$selectForm = HTMLForm::factory( 'ooui', $hidden + $selector, $this->getContext(), 'selector' );
		$selectForm->setWrapperLegendMsg( "managewiki-{$module}-select-header" );
		$selectForm->setMethod( 'post' )->setFormIdentifier( 'selector' )->setSubmitCallback( [ $this, 'reusableFormSubmission' ] )->prepareForm()->show();

		$permissionManager = MediaWikiServices::getInstance()->getPermissionManager();
		if ( $permissionManager->userHasRight( $this->getContext()->getUser(), 'managewiki-' . $module ) ) {
			$create['info'] = [
				'type' => 'info',
				'default' => $this->msg( "managewiki-{$module}-create-info" )->text(),
			];

			$create['out'] = [
				'type' => 'text',
				'label-message' => "managewiki-{$module}-create",
			];

			$createForm = HTMLForm::factory( 'ooui', $hidden + $create, $this->getContext(), 'create' );
			$createForm->setWrapperLegendMsg( "managewiki-{$module}-create-header" );
			$createForm->setMethod( 'post' )->setFormIdentifier( 'create' )->setSubmitCallback( [ $this, 'reusableFormSubmission' ] )->setSubmitText( $this->msg( "managewiki-{$module}-create-submit" )->plain() )->prepareForm()->show();
		}
	}

	public function reusableFormSubmission( array $formData, HTMLForm $form ) {
		$module = $formData['module'];
		$createNamespace = ( $form->getSubmitText() == $this->msg( 'managewiki-namespaces-create-submit' )->plain() ) ? '' : $formData['out'];
		$url = ( $module == 'namespaces' ) ? ManageWiki::namespaceID( $createNamespace ) : $formData['out'];

		if ( $module === 'namespaces' ) {
			$form->getRequest()->getSession()->set( 'create', $formData['out'] );
		}

		header( 'Location: ' . SpecialPage::getTitleFor( 'ManageWiki', $module )->getFullURL() . "/{$url}" );

		return true;
	}

	protected function getGroupName() {
		return 'wikimanage';
	}
}
