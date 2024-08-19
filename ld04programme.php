<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content
 *
 * @copyright   (C) 2006 
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\CMS\Event\Result\ResultAwareInterface; //only required if value is returned from plugin
use Joomla\CMS\Factory;


//use Joomla\String\StringHelper;


/**
 * ra programme plugin class.
 *
 */
class PlgContentLD04programme extends CMSPlugin implements SubscriberInterface {

	/**
	 * Returns an array of events this subscriber will listen to.
	 *
	 * @return  array
	 */
	public static function getSubscribedEvents(): array
	{
		return [
			'onContentPrepare' => 'ld04DisplayProgramme'
		];
	}


	/**
	 * Plugin that displays the walks programme from a (Google) web script which returns the programme in JSON format
	 *
	 * @param   string   $context  The context of the content being passed to the plugin.
	 * @param   mixed    &$article     An object with a "text" property
	 * @param   mixed    &$params  Additional parameters. See {@see PlgContentEmailcloak()}.
	 * @param   integer  $page     Optional page number. Unused. Defaults to zero.
	 *
	 * @return  boolean	True on success.
	 */
	public function ld04DisplayProgramme ($event)	{
		[$context, $article, $params, $page] = array_values($event->getArguments()); //works with J4 and J5
		// Don't run this plugin when the content is being indexed
		if ($context === 'com_finder.indexer')
		{
			return true;
		}
		$target = "{ld04programme}";

		// quick find
		$position = strpos($article->text, $target);
		if ($position === false) //not found
		{
			return true; //we are finished
		}
		else
		{
			$doc = Factory::getDocument();
			$wa  = $doc->getWebAssetManager(); //new way to insert css (and scripts)
			$user = Factory::getUser();
			$input = Factory::getApplication()->getInput();

			// overflow-x added to enable tables to be scrolled
			//register style using asset management (Joomla 4+)
			$wa->registerAndUseStyle('contentplugld04programme.tablestyle',$pluginPath.'/table.css');
			$gUri = $this->params->get("googlescripturi", 0); //note $this->params contains those set by administrator.
			$jsonFile = 'images/walks/jsonwalks';
			$webSource = false;
			$authorised = ($user->guest != '1') ? true : false; // authorised if signed in - change condition as appropriate
			
			$html = '<div class="prog">'; //the first bit of replacement html
			if ($authorised) {
				$html = $html . '<form method="post">';
				$html = $html . '<p><button name="act" value="refresh" class="link-button sunset">Load new and test</button> Load new data from the Google Doc and display the programme ' .
																				'without saving it locally</p>'; //add refresh button if authorised
				$html = $html . '<p><button name="act" value="confirm" class="link-button sunset">Confirm and save</button> Check the programme is OK ' .
																				'and save it for use</p>'; //and a confirm link
				$html = $html . '</form>';
			}
			//$input = $this->app->getInput();
			$act = $input->get('act', 'none', 'string'); //get the query value
			
			if ($act == 'refresh' && $authorised) {
				$jsonData = file_get_contents($gUri);
				if ($jsonData === false) {
				$html = $html . "<p>Unable to get the walks programme from Google web app</p>"; // no point in continuing if we cannot get the data
				$this->updateText($article, $html, $position, $target); //update with new html
				return true;
				}
				$html = $html . "<p>Walks programme refreshed. Please check content and press confirm to save it</p>";
			}
			elseif ($act == 'confirm' && $authorised) {
				$jsonData = file_get_contents($gUri);
				if ($jsonData === false) {
				$html = $html . "<p>Unable to get the walks programme from Google web app</p>"; // no point in continuing if we cannot get the data
				$this->updateText($article, $html, $position, $target); //update with new html
				return true;
				}
				if (file_put_contents($jsonFile, $jsonData) === false) {
				$html = $html . "<p>Error saving new walks programme to file. Reverting to old data...</p>";
				}
				$jsonData = file_get_contents($jsonFile); //read it back from file to be absolutely sure!
				if ($jsonData === false) {
				$html = $html . "<p>Unable to read the walks programme from file.</p>"; // no point in continuing if we cannot read the file
				$this->updateText($article, $html, $position, $target); //update with new html
				return true;
				} 
				$html = $html . "<p>Walks Programme saved.</p>";
			}
			else {
				$jsonData = file_get_contents($jsonFile);
				if ($jsonData === false) {
				$html = $html .  "<p>Currently unable to display the walks programme. Please try later</p>"; // no point in continuing if we cannot read the file
				$this->updateText($article, $html, $position, $target); //update with new html
				return true;
				} 
			}
			
			$webData = json_decode($jsonData);
			if ($webData->error) {
				$html = $html .  "<p>There was an error reading the Google document<br>Error message: " . $webData->errmsg . "</p>"; // Google script trapped an error
				$this->updateText($article, $html, $position, $target); //update with new html
				return true;
			}
			else {
				$html = $html .  "<strong style=font-size:30px;>" . $webData->message . "</strong>";
				$walkData = $webData->walklist;
			}
			//var_dump($walkData);
			
			$currentMonth = "";
			foreach ($walkData as $walk) {
				if ($walk[0] != $currentMonth) { // we need a new table
				if ($currentMonth != "") { // if this is not the first table, end the previous one
					$html = $html .  "</table>";
				} 
				$currentMonth = $walk[0];
				$html = $html . $this->tableStart($currentMonth);
				}
				$html = $html . "<tr>"; //start new row
				for ($i=1; $i<=10; $i++) { //start at 1 because we don't include the month
				$field = $walk[$i];
				$html = $html . "<td>" . $field . "</td>";
				}
				$html = $html . "</tr>"; //end row
			}
			$html = $html . "</table>";
			$html = $html .  "</div>";
			$this->updateText($article, $html, $position, $target); //update with new html
			return true;
		}

	}
	protected function tableStart($currentMonth) { // display the month, start a new table and header row
		$headerList = ["Day", "Date", "Leader", "Time", "Description", "Grid Ref", "Length (miles)", "Grade", "Car Share Contribution", "Telephone"];
		$html =  '<h2>' . $currentMonth . '</h2>';
		$html = $html . '<table style="width:100%"><tr >';
		foreach ($headerList as $headerItem) {
		$html = $html . '<th style="background-color: var(--mintcake)">'. $headerItem . '</th>';
		}
		$html = $html . '</tr>';
		return $html;
	}
	protected function updateText($article, $html, $position, $target) { //replace the target with new html
		$article->text = substr_replace($article->text, $html, $position, strlen($target));
		return;
	}

}
