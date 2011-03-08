<?php
/**
 *
 * License, TERMS and CONDITIONS
 *
 * This software is lisensed under the GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * Please read the license here : http://www.gnu.org/licenses/lgpl-3.0.txt
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * ATTRIBUTION REQUIRED
 * 4. All web pages generated by the use of this software, or at least
 * 	  the page that lists the recent questions (usually home page) must include
 *    a link to the http://www.lampcms.com and text of the link must indicate that
 *    the website\'s Questions/Answers functionality is powered by lampcms.com
 *    An example of acceptable link would be "Powered by <a href="http://www.lampcms.com">LampCMS</a>"
 *    The location of the link is not important, it can be in the footer of the page
 *    but it must not be hidden by style attibutes
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE FREEBSD PROJECT OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This product includes GeoLite data created by MaxMind,
 *  available from http://www.maxmind.com/
 *
 *
 * @author     Dmitri Snytkine <cms@lampcms.com>
 * @copyright  2005-2011 (or current year) ExamNotes.net inc.
 * @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * @link       http://www.lampcms.com   Lampcms.com project
 * @version    Release: @package_version@
 *
 *
 */


namespace Lampcms\Modules\Search;

use \Lampcms\Interfaces\Search;
use \Lampcms\Registry;

class MySQL implements Search
{
	protected $oQuestion;

	protected $qid;

	protected $oRegistry;

	protected $countResults;

	protected $term;

	protected $condition;

	protected $searchType = 't';

	/**
	 * Results per page
	 *
	 * @var int
	 */
	protected $perPage;

	protected $order = '';

	protected $pagerPath = '/search';


	protected $aRes = array();


	protected $pagerLinks = '';

	/**
	 * Search condition
	 * possible: title, body, both
	 * @var string
	 */

	const BY_TITLE = 'MATCH (title) AGAINST (:subj)';

	//const BY_TITLE_BOOL =

	const BY_TITLE_BODY = 'MATCH (title, q_body) AGAINST (:subj)';


	public function __construct(Registry $oRegistry){

		$this->oRegistry = $oRegistry;
		$this->perPage = $perPage = $this->oRegistry->Ini->PER_PAGE_SEARCH;
		if('recent' == $this->oRegistry->Request->get('ord', 's', '')){
			$this->order = 'ORDER by ts DESC';
		}
	}


	public function search($term = null){

		$this->term = (!empty($term)) ? $term : $this->oRegistry->Request['q'];

		$this->getCondition()
		->getCount()
		->getResults();

		return $this;
	}

	/**
	 * If cond[] array is present in Request
	 * then check the value(s) for title and body
	 * if both present then use 'both'
	 *
	 * Enter description here ...
	 */
	protected function getCondition(){
		$t = $this->oRegistry->Request->get('t', 's', '');

		$this->condition = ('t' == $t) ? self::BY_TITLE : self::BY_TITLE_BODY;

		d('$this->condition: ' .$this->condition);

		return $this;

	}


	protected function getCount(){
		$sql = 'SELECT count(*)
					FROM question_title
					WHERE '.$this->condition;
		try{
			$sth = $this->oRegistry->Db->makePrepared($sql);
			$sth->bindParam(':subj', $this->term, \PDO::PARAM_STR);
			$sth->execute();
		} catch(\Exception $e){
			$err = ('Exception: '.get_class($e).' Unable to insert into mysql because: '.$e->getMessage().' Err Code: '.$e->getCode().' trace: '.$e->getTraceAsString());
			d('mysql error: '.$err);

			if('42S02' === $e->getCode()){
				if(true === \Lampcms\TitleTagsTable::create($this->oRegistry)){

					return $this;
				} else {
					throw $e;
				}
			} else {
				throw $e;
			}
		}

		$this->countResults = $sth->fetchColumn();
		d('found: '.$this->countResults.' records');

		return $this;

	}


	/**
	 * (non-PHPdoc)
	 * @see Lampcms\Interfaces.Search::getResults()
	 *
	 * @todo if request is ajax we may return result
	 * via Respoder::sendAjax() - just return html block
	 * it includes pagination is necessary
	 * or we may return array or results if it's feasable
	 *
	 * @return string html of search results
	 * with pagination
	 */
	protected function getResults(){
		if(!isset($this->countResults)){
			throw new \Lampcms\DevException('count not available. You must run search() before running getCount()');
		}

		
		/**
		 * If we already know that there are no results
		 * then no need to run the same query
		 * as we did for getCount()
		 */
		if(0 === $this->countResults){
			d('count is 0, no need to run search query');

			return $this;
		}

		$offset = 0;
		$sql = "SELECT
					qid as _id, 
					title, 
					url, 
					intro, 
					DATE_FORMAT(ts, '%%M %%e, %%Y %%l:%%i %%p') as hts,
					username,
					avtr,
					tags_c,
					tags_html
					FROM question_title
					WHERE %s
					%s
					LIMIT %d
					OFFSET :offset";


		/**
		 * Now need to paginate and
		 * get value of offset
		 * and pagination links
		 * IF pagination is necessary
		 */
		if($this->countResults > $this->perPage){
			d('cp');
			$oPaginator = \Lampcms\Paginator::factory($this->oRegistry);
			$oPaginator->paginate($this->countResults, $this->perPage,
			array('path' => $this->getPagerPath()));

			$offset = ($oPaginator->getPager()->getCurrentPageID() - 1) * $this->perPage;
			d('$offset: '.$offset);
			$this->pagerLinks = $oPaginator->getLinks();
		}

		$sql = sprintf($sql, $this->condition, $this->order, $this->perPage);
		d('sql: '.$sql);
		$sth = $this->oRegistry->Db->makePrepared($sql);
		$sth->bindParam(':subj', $this->term, \PDO::PARAM_STR);
		$sth->bindParam(':offset', $offset, \PDO::PARAM_INT);
		$sth->execute();
		$this->aRes = $sth->fetchAll();

		return $this;
	}


	public function getSimilarTitles($title, $bBoolMode = true){


	}


	public function getHtml(){
		if(!isset($this->aRes)){
			throw new \Lampcms\DevException('search results not set. You must run search() before calling getHtml()');
		}

		$aTerms = array();
		$aHighlight = array();

		$func = null;
		if(!empty($this->aRes)){
			$aTerms = explode(' ', $this->term);
			d('$aTerms: '.print_r($aTerms, 1));

			foreach($aTerms as $term){
				$aHighlight[] = '<span class="match">'.trim($term).'</span>';
			}
			
			d('aTerms: '.print_r($aTerms, 1).' aHightlight: '.print_r($aHighlight, 1));
			
			$func = function(&$a) use ($aTerms, $aHighlight){
				$a['title'] = str_replace($aTerms, $aHighlight, $a['title']);
				$a['intro'] = str_replace($aTerms, $aHighlight, $a['intro']);
			};
		}

		$html = \tplSearchresults::loop($this->aRes, true, $func);

		return $html;
	}


	/**
	 * (non-PHPdoc)
	 * @see Lampcms\Interfaces.Search::count()
	 */
	public function count(){
		if(!isset($this->countResults)){
			throw new \Lampcms\DevException('count not available. You must run search() before running getCount()');
		}

		return $this->countResults;
	}


	/**
	 * Get array of up to 30
	 * similar questions, create html block from
	 * these questions and save in oQuestion
	 * under the sim_q key
	 *
	 * @param bool $ret indicats that this is a retry
	 * and prevents against retrying calling itself
	 * more than once
	 *
	 * @return object $this
	 *
	 */
	public function getSimilarQuestions(\Lampcms\Question $oQuestion){

		if(!extension_loaded('pdo_mysql')){
			d('pdo or pdo_mysql not loaded skipping parsing of similar items');

			return $this;
		}

		$qid = (int)$this->oQuestion['_id'];
		$term = $oQuestion['title'];
		$html = '';
		$aRes = array();

		$sql = "SELECT
				qid, 
				title, 
				url, 
				intro, 
				DATE_FORMAT(ts, '%M %e, %Y') as hts
				FROM question_title
				WHERE 
				qid != :qid
 				AND ".self::BY_TITLE."
				LIMIT 30";

		d('$sql: '.$sql);

		$sth = $this->oRegistry->Db->makePrepared($sql);
		$sth->bindParam(':qid', $qid, \PDO::PARAM_INT);
		$sth->bindParam(':subj', $term, \PDO::PARAM_STR);
		$sth->execute();
		$aRes = $sth->fetchAll();


		d('found '.count($aRes).' similar questions '.print_r($aRes, 1));

		if(!empty($aRes)){
			$html = \tplSimquestions::loop($aRes);
			$s = '<div id="sim_questions" class="similars">'.$html.'</div>';
			d('html: '.$s);
			$oQuestion->offsetSet('sim_q', $s);
		}

		return $this;
	}


	protected function getPagerPath(){
		return $this->pagerPath.'/'.$this->oRegistry->Request->get('ord', 's', 'm').'/' .urlencode($this->term);
	}


	public function getPagerLinks(){
		return $this->pagerLinks;
	}

}
