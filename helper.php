<?php
// Load the Twofactor_Auth_Module Class
require_once(dirname(__FILE__).'/../twofactor/authmod.php');
// Load the PHPGangsta_GoogleAuthenticator Class
require_once(dirname(__FILE__).'/GoogleAuthenticator.php');
// Load the PHP QR Code library.
require_once(dirname(__FILE__).'/phpqrcode.php');

/**
 * If we turn this into a helper class, it can have its own language and settings files.
 * Until then, we can only use per-user settings.
 */
class helper_plugin_twofactorgoogleauth extends Twofactor_Auth_Module {
	/** 
	 * The user must have verified their GA is configured correctly first.
	 */
    public function canUse($user = null){		
		return ($this->_settingExists("verified", $user) && $this->getConf('enable') === 1);
	}
	
	/**
	 * This module does provide authentication functionality at the main login screen.
	 */
    public function canAuthLogin() {
		return true;
	}
		
	/**
	 * This user will need to interact with the QR code in order to configure GA.
	 */
    public function renderProfileForm(){
		global $conf,$USERINFO;
		$elements = array();
		$ga = new PHPGangsta_GoogleAuthenticator();			
		if ($this->_settingExists("secret")) { // The user has a revokable GA secret.
			// Show the QR code so the user can add other devices.
			$mysecret = $this->_settingGet("secret");
			$data = $this->generateQRCodeData($USERINFO['name'].'@'.$conf['title'], $mysecret);			
			$elements[] = '<figure><figcaption>'.$this->getLang('directions').'</figcaption>';
			$elements[] = '<img src="'.$data.'" alt="'.$this->getLang('directions').'" />';
			$elements[] = '</figure>';
			// Check to see if the user needs to verify the code.
			if (!$this->_settingExists("verified")){
				$elements[] = '<span>'.$this->getLang('verifynotice').'</span>';
				$elements[] = form_makeTextField('googleauth_verify', '', $this->getLang('verifymodule'), '', 'block', array('size'=>'50', 'autocomplete'=>'off'));
			}
			// Show the option to revoke the GA secret.			
			$elements[] = form_makeCheckboxField('googleauth_disable', '1', $this->getLang('killmodule'), '', 'block');
		}
		else { // The user may opt in using GA.
			//Provide a checkbox to create a personal secret.			
			$elements[] = form_makeCheckboxField('googleauth_enable', '1', $this->getLang('enablemodule'), '', 'block');
		}
		return $elements;
	}

	/**
	 * Process any user configuration.
	 */	
    public function processProfileForm(){
		global $INPUT;
		$ga = new PHPGangsta_GoogleAuthenticator();
		$oldmysecret = $this->_settingGet("secret");
		if ($oldmysecret !== null) {
			if ($INPUT->bool('googleauth_disable', false)) {
				$this->_settingDelete("secret");
				// Also delete the seenqrcode attribute.  Otherwise the system will still expect the user to login with GA.
				$this->_settingDelete("verified");
				return true;
			}
			else {
				$otp = $INPUT->str('googleauth_verify', '');
				if ($otp) { // The user will use GA.
					$checkResult = $this->processLogin($otp);
					// If the code works, then flag this account to use GA.
					if ($checkResult === false) {
						return 'failed';
					}
					else {
						$this->_settingSet("verified", true);						
						return 'verified';
					}					
				}
			}
		}
		else {
			if ($INPUT->bool('googleauth_enable', false)) { // Only make a code if one is not set.
				$mysecret = $ga->createSecret();
				$this->_settingSet("secret", $mysecret);
				return true;
			}
		}
		return null;
	}	
	
	/**
	 * This module cannot send messages.
	 */
	public function canTransmitMessage() { return false; }
	
	/**
	 * Transmit the message via email to the address on file.
	 * As a special case, configure the mail settings to send only via text.
	 */
	//public function transmitMessage($message);
	
	/**
	 * 	This module authenticates against a time-based code.
	 */
    public function processLogin($code, $user = null){ 
		$ga = new PHPGangsta_GoogleAuthenticator();
		$expiry = $this->_getSharedConfig("generatorexpiry");
		$secret = $this->_settingGet("secret",'', $user);		
		return $ga->verifyCode($secret, $code, $expiry);
	}

    /**
     * Generates the QR Code used by Google Authenticator and produces a data
     * URI for direct insertion into the HTML source.
     * @param $name - The email address fo the user
     * @param $secret - The secret hash used to seed the otp formula
     * @return string - a complete data URI to be placed in an img tag's src
     *      attribute.
     */
    private function generateQRCodeData($name, $secret) {
		$url = 'otpauth://totp/'.rawurlencode($name).'?secret='.$secret;
		// Capture PNG image for embedding into HTML.
        // Thanks to https://evertpot.com/222/ for the stream tutorial
        // First create a stream that the PNG will be written into.
        $stream = fopen('php://memory','r+');
        // Write the PNG into the stream
        QRcode::png($url, $stream);     
        // Reset the stream to the start.
        rewind($stream);
        // Read in the contents of the stream.
        $image_data = stream_get_contents($stream);
		// Convert to data URI.
		return "data:image/png;base64," . base64_encode($image_data);
	}
}
