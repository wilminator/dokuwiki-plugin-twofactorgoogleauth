<?php

use dokuwiki\plugin\twofactor\Provider;
use dokuwiki\Form\Form;

// Load the PHP QR Code library.
require_once(dirname(__FILE__).'/phpqrcode.php');

/**
 * If we turn this into a helper class, it can have its own language and settings files.
 * Until then, we can only use per-user settings.
 */
class action_plugin_twofactorgoogleauth extends Provider
{

    /**
     * @inheritDoc
     */
    public function isConfigured()
    {
        return $this->settings->get('secret') &&
            $this->settings->get('verified');
    }

    /**
     * This user will need to interact with the QR code in order to configure GA.
     */
    public function renderProfileForm(Form $form)
    {
        global $conf;
        global $USERINFO;

        if ($this->settings->get('secret')) { // The user has a revokable GA secret.
            // Show the QR code so the user can add other devices.
            $mysecret = $this->settings->get('secret');
            $data = $this->generateQRCodeData($USERINFO['name'].'@'.$conf['title'], $mysecret);
            $form->addHTML('<figure><figcaption>'.$this->getLang('directions').'</figcaption>');
            $form->addHTML('<img src="'.$data.'" alt="'.$this->getLang('directions').'" />');
            $form->addHTML('</figure>');
            // Check to see if the user needs to verify the code.
            if (!$this->settings->get('verified')) {
                $form->addHTML('<span>'.$this->getLang('verifynotice').'</span><br>');
                $form->addTextInput(
                    'googleauth_verify',
                    $this->getLang('verifymodule')
                );
            }
        } else { // The user may opt in using GA.
            //Provide a checkbox to create a personal secret.
            $form->addCheckbox('googleauth_enable', $this->getLang('enablemodule'));
        }
        return $form;
    }

    /**
     * Process any user configuration.
     */
    public function handleProfileForm()
    {
        global $INPUT;
        $ga = new dokuwiki\plugin\twofactor\GoogleAuthenticator();
        $oldmysecret = $this->settings->get('secret');
        if ($oldmysecret !== null) {
            if ($INPUT->bool('googleauth_disable', false)) {
                $this->settings->delete('secret');
                // Also delete verification. Otherwise the system will still expect the user to login with GA.
                $this->settings->delete('verified');
                return true;
            } else {
                $otp = $INPUT->str('googleauth_verify', '');
                if ($otp) { // The user will use GA.
                    $checkResult = $this->processLogin($otp);
                    // If the code works, then flag this account to use GA.
                    if ($checkResult === false) {
                        return 'failed';
                    } else {
                        $this->settings->set('verified', true);
                        return 'verified';
                    }
                }
            }
        } else {
            if ($INPUT->bool('googleauth_enable', false)) { // Only make a code if one is not set.
                $mysecret = $ga->createSecret();
                $this->settings->set('secret', $mysecret);
                return true;
            }
        }
        return null;
    }

    /**
     *  This module authenticates against a time-based code.
     */
    public function processLogin($code)
    {
        $ga = new dokuwiki\plugin\twofactor\GoogleAuthenticator();
        $twofactor = plugin_load('action', 'twofactor_profile');
        $expiry = $twofactor->getConf('generatorexpiry');
        $secret = $this->settings->get('secret', '');
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
    private function generateQRCodeData($name, $secret)
    {
        $url = 'otpauth://totp/'.rawurlencode($name).'?secret='.$secret;
        // Capture PNG image for embedding into HTML.
        // Thanks to https://evertpot.com/222/ for the stream tutorial
        // First create a stream that the PNG will be written into.
        $stream = fopen('php://memory', 'r+');
        // Write the PNG into the stream
        QRcode::png($url, $stream);
        // Reset the stream to the start.
        rewind($stream);
        // Read in the contents of the stream.
        $image_data = stream_get_contents($stream);
        // Convert to data URI.
        return "data:image/png;base64," . base64_encode($image_data);
    }

    /**
     * @inheritDoc
     */
    public function transmitMessage($code)
    {
        return $this->getLang('verifymodule');
    }
}
