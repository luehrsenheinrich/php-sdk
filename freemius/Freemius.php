<?php
	/**
	 * Copyright 2014 Freemius, Inc.
	 *
	 * Licensed under the GPL v2 (the "License"); you may
	 * not use this file except in compliance with the License. You may obtain
	 * a copy of the License at
	 *
	 *     http://choosealicense.com/licenses/gpl-v2/
	 *
	 * Unless required by applicable law or agreed to in writing, software
	 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
	 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
	 * License for the specific language governing permissions and limitations
	 * under the License.
	 */

	if (!function_exists('curl_init'))
		throw new Exception('Freemius needs the CURL PHP extension.');

	require_once( dirname( __FILE__ ) . '/FreemiusBase.php' );

	define('FS_SDK__USER_AGENT', 'fs-php-' . Freemius_Api_Base::VERSION);

	$curl_version = curl_version();

	define('FS_API__PROTOCOL', version_compare($curl_version['version'], '7.37', '>=') ? 'https' : 'http');

	if (!defined('FS_API__ADDRESS'))
		define('FS_API__ADDRESS', FS_API__PROTOCOL . '://api.freemius.com');
	if (!defined('FS_API__SANDBOX_ADDRESS'))
		define('FS_API__SANDBOX_ADDRESS', FS_API__PROTOCOL . '://sandbox-api.freemius.com');

	class Freemius_Api extends Freemius_Api_Base
	{
		/**
		 * Default options for curl.
		 */
		public static $CURL_OPTS = array(
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_USERAGENT      => FS_SDK__USER_AGENT,
			CURLOPT_HTTPHEADER     => array()
		);

		/**
		 * @param string      $pScope 'app', 'developer', 'user' or 'install'.
		 * @param number      $pID Element's id.
		 * @param string      $pPublic Public key.
		 * @param string|bool $pSecret Element's secret key.
		 * @param bool        $pSandbox Whether or not to run API in sandbox mode.
		 */
		public function __construct($pScope, $pID, $pPublic, $pSecret = false, $pSandbox = false)
		{
			// If secret key not provided, user public key encryption.
			if (is_bool($pSecret))
				$pSecret = $pPublic;

			parent::Init($pScope, $pID, $pPublic, $pSecret, $pSandbox);
		}

		public function GetUrl($pCanonizedPath = '')
		{
			return ($this->_sandbox ? FS_API__SANDBOX_ADDRESS : FS_API__ADDRESS) . $pCanonizedPath;
		}

		/**
		 * @var int Clock diff in seconds between current server to API server.
		 */
		private static $_clock_diff = 0;

		/**
		 * Set clock diff for all API calls.
		 *
		 * @since 1.0.3
		 * @param $pSeconds
		 */
		public static function SetClockDiff($pSeconds)
		{
			self::$_clock_diff = $pSeconds;
		}

		/**
		 * Sign request with the following HTTP headers:
		 *      Content-MD5: MD5(HTTP Request body)
		 *      Date: Current date (i.e Sat, 14 Feb 2015 20:24:46 +0000)
		 *      Authorization: FS {scope_entity_id}:{scope_entity_public_key}:base64encode(sha256(string_to_sign, {scope_entity_secret_key}))
		 *
		 * @param string $pResourceUrl
		 * @param array  $opts
		 * @param string $pJsonEncodedParams
		 * @param string $pContentType
		 */
		protected function SignRequest($pResourceUrl, &$opts, $pJsonEncodedParams, $pContentType)
		{
			$eol = "\n";
			$content_md5 = '';
			$now = (time() - self::$_clock_diff);
			$date = date('r', $now);

			if (isset($opts[CURLOPT_POST]) && 0 < $opts[CURLOPT_POST])
			{
			    if ( ! empty($pJsonEncodedParams))
    				$content_md5 = md5($pJsonEncodedParams);

				$opts[CURLOPT_HTTPHEADER][] = 'Content-MD5: ' . $content_md5;
			}

			$opts[CURLOPT_HTTPHEADER][] = 'Date: ' . $date;

			$string_to_sign = implode($eol, array(
				$opts[CURLOPT_CUSTOMREQUEST],
				$content_md5,
                $pContentType,
				$date,
				$pResourceUrl
			));

			// If secret and public keys are identical, it means that
			// the signature uses public key hash encoding.
			$auth_type = ($this->_secret !== $this->_public) ? 'FS' : 'FSP';

			// Add authorization header.
			$opts[CURLOPT_HTTPHEADER][] = 'Authorization: ' . $auth_type . ' ' . $this->_id . ':' . $this->_public . ':' . self::Base64UrlEncode(hash_hmac('sha256', $string_to_sign, $this->_secret));
		}

		/**
		 * Makes an HTTP request. This method can be overridden by subclasses if
		 * developers want to do fancier things or use something other than curl to
		 * make the request.
		 *
		 * @param $pCanonizedPath The URL to make the request to
		 * @param string $pMethod HTTP method
		 * @param array $pParams The parameters to use for the POST body
		 * @param array $pFileParams
		 * @param null $ch Initialized curl handle
		 *
		 * @return mixed
		 * @throws Freemius_Exception
		 */
		public function MakeRequest($pCanonizedPath, $pMethod = 'GET', $pParams = array(), $pFileParams = array(), $ch = null)
		{
			if (!$ch)
				$ch = curl_init();

			$opts = self::$CURL_OPTS;

			if (!is_array($opts[CURLOPT_HTTPHEADER]))
				$opts[CURLOPT_HTTPHEADER] = array();

			$content_type        = 'application/json';
            $json_encoded_params = empty($pParams) ?
                '' :
                json_encode($pParams);

			if ('POST' === $pMethod || 'PUT' === $pMethod)
			{
                if ( ! empty($pFileParams))
                {
                    $data = empty($json_encoded_params) ?
                        '' :
                        array('data' => $json_encoded_params);

                    $json_encoded_params = '';

                    $boundary     = ('----' . uniqid());
                    $post_fields  = $this->GenerateMultipartBody($data, $pFileParams, $boundary);
                    $content_type = "multipart/form-data; boundary={$boundary}";
                }
                else
                {
                    $post_fields = $json_encoded_params;
                }

                if (is_array($pParams) && 0 < count($pParams)) {
					$opts[ CURLOPT_POST ]       = count( $pParams );
					$opts[ CURLOPT_POSTFIELDS ] = $post_fields;
				}

				$opts[CURLOPT_RETURNTRANSFER] = true;
			}

            $opts[CURLOPT_HTTPHEADER][] = "Content-Type: $content_type";

            $request_url = $this->GetUrl($pCanonizedPath);

			$opts[CURLOPT_URL] = $request_url;
			$opts[CURLOPT_CUSTOMREQUEST] = $pMethod;

			$resource = explode('?', $pCanonizedPath);
			$this->SignRequest($resource[0], $opts, $json_encoded_params, $content_type);

			// disable the 'Expect: 100-continue' behaviour. This causes CURL to wait
			// for 2 seconds if the server does not support this header.
			$opts[CURLOPT_HTTPHEADER][] = 'Expect:';

			if ('https' === substr(strtolower($request_url), 0, 5))
			{
				$opts[CURLOPT_SSL_VERIFYHOST] = false;
				$opts[CURLOPT_SSL_VERIFYPEER] = false;
			}

			curl_setopt_array($ch, $opts);
			$result = curl_exec($ch);

			/*if (curl_errno($ch) == 60) // CURLE_SSL_CACERT
			{
				self::errorLog('Invalid or no certificate authority found, using bundled information');
				curl_setopt($ch, CURLOPT_CAINFO,
				dirname(__FILE__) . '/fb_ca_chain_bundle.crt');
				$result = curl_exec($ch);
			}*/

			// With dual stacked DNS responses, it's possible for a server to
			// have IPv6 enabled but not have IPv6 connectivity.  If this is
			// the case, curl will try IPv4 first and if that fails, then it will
			// fall back to IPv6 and the error EHOSTUNREACH is returned by the
			// operating system.
			if (false === $result && empty($opts[CURLOPT_IPRESOLVE]))
			{
				$matches = array();
				$regex = '/Failed to connect to ([^:].*): Network is unreachable/';
				if (preg_match($regex, curl_error($ch), $matches))
				{
					if (strlen(@inet_pton($matches[1])) === 16)
					{
						self::errorLog('Invalid IPv6 configuration on server, Please disable or get native IPv6 on your server.');
						self::$CURL_OPTS[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
						curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
						$result = curl_exec($ch);
					}
				}
			}

			if ($result === false)
			{
				$e = new Freemius_Exception(array(
					'error' => array(
						'code' => curl_errno($ch),
						'message' => curl_error($ch),
						'type' => 'CurlException',
					),
				));

				curl_close($ch);
				throw $e;
			}

			curl_close($ch);

			return $result;
		}

        /**
         * @param array  $pParams
         * @param array  $pFileParams
         * @param string $pBoundary
         *
         * @return string
         */
		private function GenerateMultipartBody($pParams, $pFileParams, $pBoundary)
        {
            $body = '';

            if ( ! empty($pParams))
            {
                foreach ($pParams as $name => $value) {
                    $body = ('--' . $pBoundary . PHP_EOL) .
                        ("Content-Disposition: form-data; name=\"{$name}\"" . PHP_EOL) .
                        PHP_EOL .
                        ($value . PHP_EOL);
                }
            }

            foreach ($pFileParams as $name => $file_path) {
                $filename = basename($file_path);

                $body .=
                    ('--' . $pBoundary . PHP_EOL) .
                    ("Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"" . PHP_EOL) .
                    ('Content-Type: ' . $this->GetMimeContentType(strtolower($filename)) . PHP_EOL) .
                    PHP_EOL .
                    (file_get_contents($file_path) . PHP_EOL);
            }

            $body .= ('--' . $pBoundary . '--');

            return $body;
        }

        /**
         * @param string $pFilename
         *
         * @return string
         *
         * @throws Exception
         */
        private function GetMimeContentType($pFilename)
        {
            if (function_exists('mime_content_type'))
                return mime_content_type($pFilename);

            $mime_types = array(
                'zip'  => 'application/zip',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
            );

            $ext = explode('.', $pFilename)[1];

            if ( ! isset($mime_types[$ext]))
                throw new Exception('Unknown file type');

            return $mime_types[$ext];
        }
	}