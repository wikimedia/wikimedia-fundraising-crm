<?php
namespace wmf_communication;

use \BaseWmfDrupalPhpUnitTestCase;

/**
 * @group WmfCommunication
 */
class TemplatingTest extends BaseWmfDrupalPhpUnitTestCase {

	protected function getParams() {
		return array(
			'locale' => 'it',
			'name' => 'Test Donor',
			'receive_date' => 'Smarch 13th',
			'currency' => 'EUR',
			'amount' => 55.22
		);
	}

	protected static $expected = "<p>Caro Test Donor,</p>

<p>Grazie per la tua donazione alla Wikimedia Foundation. È stata davvero apprezzata di
cuore!</p>

<p>Per le tue registrazioni: La tua donazione il Smarch 13th è stata
€ 55,22.</p>
";

	public function testRender() {
		$params = $this->getParams();
		$template = new Templating(
			__DIR__ . DIRECTORY_SEPARATOR . '../templates',
			'thank_you',
			$params['locale'],
			$params
		);

		$rendered = $template->render( 'html' );
		$this->assertEquals( self::$expected, $rendered );
	}

	public function testRenderWithFallback() {
		$params = $this->getParams();
		$params['locale'] = 'it_FR';
		$template = new Templating(
			__DIR__ . DIRECTORY_SEPARATOR . '../templates',
			'thank_you',
			$params['locale'],
			$params
		);

		$rendered = $template->render( 'html' );
		$this->assertEquals( self::$expected, $rendered );
	}
}
