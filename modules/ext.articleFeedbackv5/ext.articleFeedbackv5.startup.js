/**
 * Script for Article Feedback Extension: Article pages
 */

/*** Main entry point ***/
jQuery( function( $ ) {

	// start AFTv5
	var load = function() {
		// remove (if any) occurrences of older AFT variations
		var removeAft = function() {
			var $aft = $( '#mw-articlefeedback' );
			if ( $aft.length > 0 ) {
				$aft.remove();
			} else {
				clearInterval( removeAftInterval );
			}
		};
		var removeAftInterval = setInterval( removeAft, 100 );

		// load AFTv5
		mw.loader.load( 'ext.articleFeedbackv5' );
		// Load the IE-specific module
		if ( navigator.appVersion.indexOf( 'MSIE 7' ) != -1 ) {
			mw.loader.load( 'ext.articleFeedbackv5.ie' );
		}
	}

	// check if AFT is enabled (for this user)
	var enable = $.aftUtils.verify( 'article' );
	if ( enable ) {
		load();
	}

	// check if user can enable AFTv5
	if ( $.aftUtils.canSetStatus( true ) ) {
		var userPermissions = mw.config.get( 'wgArticleFeedbackv5Permissions' );

		// build link to enable feedback form
		var $link = $( '<li id="t-articlefeedbackv5-enable"><a href="#"></a></li>' );
		$link.find( 'a' ).text( mw.msg( 'articlefeedbackv5-toolbox-enable' ) );

		// administrators can change detailed visibility in ?action=protect
		if ( 'aft-administrator' in userPermissions && userPermissions['aft-administrator'] ) {
			var link = mw.config.get( 'wgScript' ) + '?title=' +
				encodeURIComponent( mw.config.get( 'wgPageName' ) ) +
				'&' + $.param( { action: 'protect' } );

			$link.find( 'a' ).attr( 'href', link );

		// editors can enable/disable for readers via API
		} else {
			$link.find( 'a' ).on( 'click', function( e ) {
				e.preventDefault();

				$.aftUtils.setStatus( $.aftUtils.article().id, 1, function( data ) {
					if ( 'result' in data ) {
						if ( data.result === 'Success' ) {
							$link.remove();

							// display AFTv5 tool (unless this user already had access, in which case it's already visible)
							if ( !enable ) {
								load();
							}

							// add message to confirm AFTv5 has just been enabled
							var link = mw.config.get( 'wgArticleFeedbackv5SpecialUrl' ) + '/' + mw.config.get( 'wgPageName' );
							var message =
								$( '<p id="articleFeedbackv5-added"></p>' )
									.msg( 'articlefeedbackv5-enabled-form-message', link );
							$( '#mw-articlefeedbackv5' ).append( message );

							// move down to AFTv5 form
							location.hash = "#mw-articlefeedbackv5";

						} else if ( data.result === 'Error' && data.reason ) {
							alert( mw.msg( data.reason ) );
						}
					}
				} );
			});
		}

		$( '#p-tb' ).find( 'ul' ).append( $link );
	}

} );
