<?php
/**
 * ApiViewFeedbackArticleFeedbackv5 class
 *
 * @package    ArticleFeedback
 * @subpackage Api
 * @author     Greg Chiasson <greg@omniti.com>
 */

/**
 * This class pulls the individual ratings/comments for the feedback page.
 *
 * @package    ArticleFeedback
 * @subpackage Api
 */
class ApiViewFeedbackArticleFeedbackv5 extends ApiQueryBase {
	private $access = array();
	/**
	 * Constructor
	 */
	public function __construct( $query, $moduleName ) {
		parent::__construct( $query, $moduleName, 'afvf' );
		$this->access = ApiArticleFeedbackv5Utils::initializeAccess();
	}

	/**
	 * Execute the API call: Pull the requested feedback
	 */
	public function execute() {
		$params   = $this->extractRequestParams();
		$result   = $this->getResult();
		$pageId   = $params['pageid'];
		$html     = '';
		$length   = 0;
		$count    = $this->fetchFeedbackCount( $params['pageid'], $params['filter'], $params['filtervalue'] );
		$feedback = $this->fetchFeedback(
			$params['pageid'],
			$params['filter'],
			$params['filtervalue'],
			$params['sort'],
			$params['sortdirection'],
			$params['limit'],
			( $params['continue'] !== 'null' ? $params['continue'] : null )
		);

		foreach ( $feedback as $record ) {
			$html .= $this->renderFeedback( $record );
			$length++;
		}
		$tmp = end($feedback);
		if( $tmp ) {
			$continue = $tmp[0]->af_id;
		}

		$result->addValue( $this->getModuleName(), 'length', $length );
		$result->addValue( $this->getModuleName(), 'count', $count );
		$result->addValue( $this->getModuleName(), 'feedback', $html );
		if ( $continue ) {
			$result->addValue( $this->getModuleName(), 'continue', $continue );
		}
	}

	public function fetchFeedbackCount( $pageId, $filter, $filterValue ) {
		$dbr   = wfGetDB( DB_SLAVE );
		$count = $dbr->selectField(
			array( 'aft_article_filter_count' ),
			array( 'afc_filter_count' ),
			array(
				'afc_page_id'     => $pageId,
				'afc_filter_name' => $filter
			),
			__METHOD__
		);
		// selectField returns false if there's no row, so make that 0
		return $count ? $count : 0;
	}

	public function fetchFeedback( $pageId,
	 $filter = 'visible', $filterValue = null, $sort = 'age', $sortOrder = 'desc', $limit = 25, $continue = null ) {
		$dbr   = wfGetDB( DB_SLAVE );
		$ids   = array();
		$rows  = array();
		$rv    = array();
		$where = $this->getFilterCriteria( $filter, $filterValue );

		$direction         = strtolower( $sortOrder ) == 'asc' ? 'ASC' : 'DESC';
		$continueDirection = ( $direction == 'ASC' ? '>' : '<' );
		$order;
		$continue;

		switch( $sort ) {
			case 'helpful':
				$order       = "net_helpfulness $direction";
				$continueSql = "net_helpfulness $continueDirection";
				break;
			case 'rating':
				# For now, just fall through. Not specced out.
				break;
			case 'age':
				# Default sort is by age.
			default:
				$order       = "af_id $direction";
				$continueSql = "af_id $continueDirection";
				break;
		}

		$where['af_page_id'] = $pageId;
		# This join is needed for the comment filter.
		$where[] = 'af_id = aa_feedback_id';

		if( $continue !== null ) {
			$where[] = "$continueSql $continue";
		}

		/* I'd really love to do this in one big query, but MySQL
		   doesn't support LIMIT inside IN() subselects, and since
		   we don't know the number of answers for each feedback
		   record until we fetch them, this is the only way to make
		   sure we get all answers for the exact IDs we want. */
		$id_query = $dbr->select(
			array(
				'aft_article_feedback', 
				'aft_article_answer'
			),
			array(
				'DISTINCT af_id', 
				'CONVERT(af_helpful_count, SIGNED) - CONVERT(af_unhelpful_count, SIGNED) AS net_helpfulness'
			),
			$where, 
			__METHOD__,
			array(
				'LIMIT'    => $limit,
				'ORDER BY' => $order
			)
		);

		foreach ( $id_query as $id ) {
			$ids[] = $id->af_id;
		}

		if ( !count( $ids ) ) {
			return array();
		}

		$rows  = $dbr->select(
			array( 'aft_article_feedback', 'aft_article_answer',
				'aft_article_field', 'aft_article_field_option',
				'user', 'page'
			),
			array( 'af_id', 'af_bucket_id', 'afi_name', 'afo_name',
				'aa_response_text', 'aa_response_boolean',
				'aa_response_rating', 'aa_response_option_id',
				'afi_data_type', 'af_created', 'user_name',
				'af_user_ip', 'af_hide_count', 'af_abuse_count',
				'af_helpful_count', 'af_unhelpful_count', 'af_delete_count', 
				'(SELECT COUNT(*) FROM revision WHERE rev_id > af_revision_id AND rev_page = '.( integer ) $pageId.') AS age', 
				'CONVERT(af_helpful_count, SIGNED) - CONVERT(af_unhelpful_count, SIGNED) AS net_helpfulness',
				'page_latest', 'af_revision_id'
			),
			array( 'af_id' => $ids ),
			__METHOD__,
			array( 'ORDER BY' => $order ),
			array(
				'page'                     => array(
					'JOIN', 'page_id = af_page_id'
				),
				'user'                     => array(
					'LEFT JOIN', 'user_id = af_user_id'
				),
				'aft_article_field'        => array(
					'LEFT JOIN', 'afi_id = aa_field_id'
				),
				'aft_article_answer'       => array(
					'LEFT JOIN', 'af_id = aa_feedback_id'
				),
				'aft_article_field_option' => array(
					'LEFT JOIN',
					'aa_response_option_id = afo_option_id'
				)
			)
		);

		foreach ( $rows as $row ) {
			if ( !array_key_exists( $row->af_id, $rv ) ) {
				$rv[$row->af_id]    = array();
				$rv[$row->af_id][0] = $row;
				$rv[$row->af_id][0]->user_name = $row->user_name ? $row->user_name : $row->af_user_ip;
			}
			$rv[$row->af_id][$row->afi_name] = $row;
		}

		return $rv;
	}

	private function getContinue( $sort, $sortOrder ) {
		$continue;
		$direction = strtolower( $sortOrder ) == 'asc' ? '<' : '>';

		switch( $sort ) {
			case 'helpful':
				$continue = 'net_helpfulness <';
				break;
			case 'rating':
				# For now, just fall through. Not specced out.
				break;
			case 'age':
				# Default sort is by age.
			default:
				$continue  = 'af_id >';
				break;
		}

		return $continue;
	}

	private function getFilterCriteria( $filter, $filterValue = null ) {
		$where = array();

		// Permissions check
		if( 
			( $filter == 'invisible' && !$this->access[ 'rollbackers' ] )
			|| ( $filter == 'deleted' && !$this->access[ 'oversight' ] )

		) {
			$filter = null;
		}

		switch( $filter ) {
			case 'all':
				$where = array();
				break;
			case 'invisible':
				$where = array( 'af_hide_count > 0' );
 				break;
			case 'comment':
				$where = array( 'aa_response_text IS NOT NULL');
				break;
			case 'id':
				$where = array( 'af_id' => $filterValue );
				break;
			case 'visible':
			default:
				$where = array( 'af_hide_count' => 0 );
				break;
		}
		return $where;
	}

	protected function renderFeedback( $record ) {
		global $wgArticlePath;
		switch( $record[0]->af_bucket_id ) {
			case 1: $content .= $this->renderBucket1( $record ); break;
			case 2: $content .= $this->renderBucket2( $record ); break;
			case 3: $content .= $this->renderBucket3( $record ); break;
			case 4: $content .= $this->renderBucket4( $record ); break;
			case 5: $content .= $this->renderBucket5( $record ); break;
			case 6: $content .= $this->renderBucket6( $record ); break;
			default: $content .= $this->renderNoBucket( $record ); break;
		}
		$can_flag   = !$this->access[ 'blocked' ];
		$can_vote   = !$this->access[ 'blocked' ];
		$can_hide   = $this->access[ 'rollbackers' ];
		$can_delete = $this->access[ 'oversight' ];
		$id         = $record[0]->af_id;

#		$header_links = Html::openElement( 'p', array( 'class' => 'articleFeedbackv5-comment-head' ) )
#		. Html::element( 'a', array( 'class' => 'articleFeedbackv5-comment-name', 'href' => 'profilepage or whatever' ), $id )
#		. Html::openElement( 'div', array(
#		. Html::element( 'span', array( 'class' => 'articleFeedbackv5-comment-timestamp' ), $record[0]->af_created )
#		. wfMessage( 'articlefeedbackv5-form-optionid', $record[0]->af_bucket_id )->escaped()
#		. Html::closeElement( 'p' );

		$details = Html::openElement( 'div', array(
			'class' => 'articleFeedbackv5-comment-details'
		) )
		. Html::openElement( 'div', array(
			'class' => 'articleFeedbackv5-comment-details-date'
		) ) 
		.Html::element( 'a', array(
			'href' => "#id=$id"
		), date( 'M j, Y H:i', strtotime($record[0]->af_created) ) )
		. Html::closeElement( 'div' )
# Remove for now, pending feedback.
#		. Html::openElement( 'div', array(
#			'class' => 'articleFeedbackv5-comment-details-permalink'
#		) )
#		.Html::element( 'a', array(
#			'href' => "#id=$id"
#		), wfMessage( 'articlefeedbackv5-comment-link' ) )
#		. Html::closeElement( 'div' )

		. Html::openElement( 'div', array(
			'class' => 'articleFeedbackv5-comment-details-updates'
		) ) 
		. Linker::link(
#TODO: take out that hardcoded thing.
			Title::newFromText( 'Greg' ),
			wfMessage( 'articlefeedbackv5-updates-since',  $record[0]->age ), 
			array(),
			array(
				'action' => 'historysubmit',
				'diff'   => $record[0]->page_latest,
				'oldid'  => $record[0]->af_revision_id
			)
		)
#		), wfMessage( 'articlefeedbackv5-updates-since',  $record[0]->age ) )
		. Html::closeElement( 'div' )
		. Html::closeElement( 'div' );
;

		$footer_links = Html::openElement( 'p', array( 'class' => 'articleFeedbackv5-comment-foot' ) );

		if( $can_vote ) {
			$footer_links .= Html::element( 'span', array(
				'class' => 'articleFeedbackv5-helpful-caption'
			), wfMessage( 'articlefeedbackv5-form-helpful-label', ( $record[0]->af_helpful_count + $record[0]->af_unhelpful_count ) ) )
			. Html::element( 'a', array(
				'id'    => "articleFeedbackv5-helpful-link-$id",
				'class' => 'articleFeedbackv5-helpful-link'
			), wfMessage( 'articlefeedbackv5-form-helpful-yes-label', $record[0]->af_helpful_count )->text() )
			.Html::element( 'a', array(
				'id'    => "articleFeedbackv5-unhelpful-link-$id",
				'class' => 'articleFeedbackv5-unhelpful-link'
			), wfMessage( 'articlefeedbackv5-form-helpful-no-label', $record[0]->af_unhelpful_count )->text() );
		}

		$footer_links .= Html::element( 'span', array(
			'class' => 'articleFeedbackv5-helpful-votes'
		), wfMessage( 'articlefeedbackv5-form-helpful-votes', ( $record[0]->af_helpful_count + $record[0]->af_unhelpful_count ), $record[0]->af_helpful_count, $record[0]->af_unhelpful_count ) )
		. Html::closeElement( 'p' );


#		$rate = Html::openElement( 'div', array( 'class' => 'articleFeedbackv5-feedback-rate' ) )
#		. wfMessage( 'articlefeedbackv5-form-helpful-label' )->escaped()
#		. Html::closeElement( 'div' );


		$tools = Html::openElement( 'div', array( 
			'class' => 'articleFeedbackv5-feedback-tools',
			'id'    => 'articleFeedbackv5-feedback-tools-'.$id
		) )
		. Html::element( 'h3', array(
			'id' => 'articleFeedbackv5-feedback-tools-header-'.$id
		), wfMessage( 'articlefeedbackv5-form-tools-label' )->text() )
		. Html::openElement( 'ul', array(
			'id' => 'articleFeedbackv5-feedback-tools-list-'.$id
		) )
		# TODO: unhide hidden posts
		. ( $can_hide ? Html::rawElement( 'li', array(), Html::element( 'a', array(
			'id'    => "articleFeedbackv5-hide-link-$id",
			'class' => 'articleFeedbackv5-hide-link'
		), wfMessage( 'articlefeedbackv5-form-hide', $record[0]->af_hide_count )->text() ) ) : '' )
		. ( $can_flag ? Html::rawElement( 'li', array(), Html::element( 'a', array(
			'id'    => "articleFeedbackv5-abuse-link-$id",
			'class' => 'articleFeedbackv5-abuse-link'
		), wfMessage( 'articlefeedbackv5-form-abuse', $record[0]->af_abuse_count )->text() ) ) : '' )
		# TODO: nonoversight can mark for oversight, oversight can 
		# either delete or un-delete, based on deletion status
		. ( $can_delete ? Html::rawElement( 'li', array(), Html::element( 'a', array(
			'id'    => "articleFeedbackv5-delete-link-$id",
			'class' => 'articleFeedbackv5-delete-link'
		), wfMessage( 'articlefeedbackv5-form-delete' )->text() ) ) : '' )
		. Html::closeElement( 'ul' )
		. Html::closeElement( 'div' );

		# Only set a wrapper class for bucket 1.
		$class = '';
		if( array_key_exists( 'found', $record ) ) {
			if ( $record['found']->aa_response_boolean ) {
				$class = 'positive';
			} else {
				$class = 'negative';
			}
		}

		return Html::openElement( 'div', array( 'class' => 'articleFeedbackv5-feedback' ) )
		. Html::openElement( 'div', array(
			'class' => "articleFeedbackv5-comment-wrap $class"
		) )
		. $content
		. $footer_links
		. Html::closeElement( 'div' )
		. $details
		. $tools
		. $rate
		. Html::closeElement( 'div' );
	}

	private function renderBucket1( $record ) {
		$name = htmlspecialchars( $record[0]->user_name );
		$link = $record[0]->af_user_id ? "User:$name" : "Special:Contributions/$name";

		if ( $record['found']->aa_response_boolean ) {
			$msg   = 'articlefeedbackv5-form1-header-found';
			$class = 'positive';
		} else {
			$msg   = 'articlefeedbackv5-form1-header-not-found';
			$class = 'negative';
		}
		$found = Html::openElement( 'h3' )
		. Html::element( 'span', array( 'class' => 'icon' ) )
                . Linker::link( Title::newFromText( $link ), $name )
		.Html::element( 'span', array(
			'class' => $class,
		), wfMessage( $msg, '')->escaped() )
		. Html::closeElement( 'h3' );

		return "$found
		<blockquote>" . htmlspecialchars( $record['comment']->aa_response_text )
		. '</blockquote>';
	}

	private function renderBucket2( $record ) {
		$name = htmlspecialchars( $record[0]->user_name );
		$type = htmlspecialchars( $record['tag']->afo_name );
		// Document for grepping. Uses any of the messages:
		// * articlefeedbackv5-form2-header-praise
		// * articlefeedbackv5-form2-header-problem
		// * articlefeedbackv5-form2-header-question
		// * articlefeedbackv5-form2-header-suggestion
		return 
		Html::openElement( 'h3' )
		. wfMessage( 'articlefeedbackv5-form2-header-' . $type, $name )->escaped()
		. Html::closeElement( 'h3' )
		. '<blockquote>' . htmlspecialchars( $record['comment']->aa_response_text )
		. '</blockquote>';
	}

	# TODO: The headers here really need the same treatment as bucket1, with
	# the links and such.
	private function renderBucket3( $record ) {
		$name   = htmlspecialchars( $record[0]->user_name );
		$rating = htmlspecialchars( $record['rating']->aa_response_rating );
		return 
		Html::openElement( 'h3' )
		. wfMessage( 'articlefeedbackv5-form3-header', $name, $rating )->escaped()
		. Html::closeElement( 'h3' )
		. '<blockquote>' . htmlspecialchars( $record['comment']->aa_response_text )
		. '</blockquote>';
	}

	private function renderBucket4( $record ) {
		return wfMessage( 'articlefeedbackv5-form4-header' )->escaped();
	}

	private function renderBucket5( $record ) {
		$name = htmlspecialchars( $record[0]->user_name );
		$rv   = 
		Html::openElement( 'h3' )
		. wfMessage( 'articlefeedbackv5-form5-header', $name )->escaped()
		. Html::closeElement( 'h3' );
		$rv .= '<ul>';
		foreach ( $record as $key => $answer ) {
			if ( $answer->afi_data_type == 'rating' && $key != '0' ) {
				$rv .= "<li>"
				. htmlspecialchars( $answer->afi_name  )
				. ': '
				. htmlspecialchars( $answer->aa_response_rating )
				. "</li>";
			}
		}
		$rv .= "</ul>";

		return $rv;
	}

	private function renderBucket0( $record ) {
		# Future-proof this for when the bucket ID changes to 0.
		return $this->renderBucket6( $record );
	}

	private function renderNoBucket( $record ) {
		return wfMessage( 'articlefeedbackv5-form-invalid' )->escaped();
	}

	private function renderBucket6( $record ) {
		return wfMessage( 'articlefeedbackv5-form-not-shown' )->escaped();
	}

	/**
	 * Gets the allowed parameters
	 *
	 * @return array the params info, indexed by allowed key
	 */
	public function getAllowedParams() {
		return array(
			'pageid'        => array(
				ApiBase::PARAM_REQUIRED => true,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => 'integer'
			),
			'sort'          => array(
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => array(
				 'age', 'helpful', 'rating' )
			),
			'sortdirection' => array(
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => array(
				 'desc', 'asc' )
			),
			'filter'        => array(
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => array(
				 'all', 'invisible', 'visible', 'comment', 'id' )
			),
			'filtervalue'   => array(
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => 'string'
			),
			'limit'         => array(
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => 'integer'
			),
			'continue'      => array(
				ApiBase::PARAM_REQUIRED => false,
				ApiBase::PARAM_ISMULTI  => false,
				ApiBase::PARAM_TYPE     => 'string'
			),
		);
	}

	/**
	 * Gets the parameter descriptions
	 *
	 * @return array the descriptions, indexed by allowed key
	 */
	public function getParamDescription() {
		return array(
			'pageid'      => 'Page ID to get feedback ratings for',
			'sort'        => 'Key to sort records by',
			'filter'      => 'What filtering to apply to list',
			'filtervalue' => 'Optional param to pass to filter',
			'limit'       => 'Number of records to show',
			'continue'    => 'Offset from which to continue',
		);
	}

	/**
	 * Gets the api descriptions
	 *
	 * @return array the description as the first element in an array
	 */
	public function getDescription() {
		return array(
			'List article feedback for a specified page'
		);
	}

	/**
	 * Gets any possible errors
	 *
	 * @return array the errors
	 */
	public function getPossibleErrors() {
		return array_merge( parent::getPossibleErrors(), array(
				array( 'missingparam', 'anontoken' ),
				array( 'code' => 'invalidtoken', 'info' => 'The anontoken is not 32 characters' ),
			)
		);
	}

	/**
	 * Gets an example
	 *
	 * @return array the example as the first element in an array
	 */
	public function getExamples() {
		return array(
			'api.php?action=query&list=articlefeedbackv5-view-feedback&afpageid=1',
		);
	}

	/**
	 * Gets the version info
	 *
	 * @return string the SVN version info
	 */
	public function getVersion() {
		return __CLASS__ . ': $Id: ApiViewRatingsArticleFeedbackv5.php 103439 2011-11-17 03:19:01Z rsterbin $';
	}
}
