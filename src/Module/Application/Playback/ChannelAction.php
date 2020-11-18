<?php

/*
 * vim:set softtabstop=4 shiftwidth=4 expandtab:
 *
 * LICENSE: GNU Affero General Public License, version 3 (AGPL-3.0-or-later)
 * Copyright 2001 - 2020 Ampache.org
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 */

declare(strict_types=0);

namespace Ampache\Module\Application\Playback;

use Ampache\Config\AmpConfig;
use Ampache\Model\Channel;
use Ampache\Model\Preference;
use Ampache\Model\User;
use Ampache\Module\Application\ApplicationActionInterface;
use Ampache\Module\Authentication\AuthenticationManagerInterface;
use Ampache\Module\Authorization\Access;
use Ampache\Module\System\Core;
use Ampache\Module\Util\Ui;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ChannelAction implements ApplicationActionInterface
{
    public const REQUEST_KEY = 'channel';

    private AuthenticationManagerInterface $authenticationManager;

    public function __construct(
        AuthenticationManagerInterface $authenticationManager
    ) {
        $this->authenticationManager = $authenticationManager;
    }

    public function run(ServerRequestInterface $request): ?ResponseInterface
    {
        ob_end_clean();

        set_time_limit(0);

        $channel = new Channel((int) Core::get_request('channel'));
        if (!$channel->id) {
            debug_event('channel/index', 'Unknown channel.', 1);

            return null;
        }

        if (!function_exists('curl_version')) {
            debug_event('channel/index', 'Error: Curl is required for this feature.', 2);

            return null;
        }

        // Authenticate the user here
        if ($channel->is_private) {
            $is_auth = false;
            if (filter_has_var(INPUT_SERVER, 'PHP_AUTH_USER')) {
                $htusername = Core::get_server('PHP_AUTH_USER');
                $htpassword = Core::get_server('PHP_AUTH_PW');

                $auth = $this->authenticationManager->login($htusername, $htpassword);
                debug_event('channel/index', 'Auth Attempt for ' . $htusername, 5);
                if ($auth['success']) {
                    debug_event('channel/index', 'Auth SUCCESS', 3);
                    $username        = $auth['username'];
                    $GLOBALS['user'] = User::get_from_username($username);
                    $is_auth         = true;
                    Preference::init();

                    if (AmpConfig::get('access_control')) {
                        if (!Access::check_network('stream', Core::get_global('user')->id, 25) &&
                            !Access::check_network('network', Core::get_global('user')->id, 25)) {
                            debug_event('channel/index', "UI::access_denied: Streaming Access Denied: " . Core::get_user_ip() . " does not have stream level access", 2);
                            Ui::access_denied();

                            return null;
                        }
                    }
                }
            }

            if (!$is_auth) {
                debug_event('channel/index', 'Auth FAILURE', 3);
                header('WWW-Authenticate: Basic realm="Ampache Channel Authentication"');
                header('HTTP/1.0 401 Unauthorized');
                echo T_('Unauthorized');

                return null;
            }
        }

        $url = 'http://' . $channel->interface . ':' . $channel->port . '/' . $_REQUEST['target'];
        // Redirect request to the real channel server
        $headers         = getallheaders();
        $headers['Host'] = $channel->interface;
        $reqheaders      = array();
        foreach ($headers as $key => $value) {
            $reqheaders[] = $key . ': ' . $value;
        }

        $curl = curl_init($url);
        if ($curl) {
            curl_setopt_array($curl, array(
                CURLOPT_HTTPHEADER => $reqheaders,
                CURLOPT_HEADER => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HEADERFUNCTION => static function ($curl, $header) {
                    $trimheader = trim($header);
                    if (!empty($trimheader)) {
                        header($trimheader);
                    }

                    return strlen($header);
                },
                CURLOPT_NOPROGRESS => false,
                CURLOPT_PROGRESSFUNCTION => static function ($totaldownload, $downloaded, $us, $ud) {
                    flush();
                    ob_flush();
                }
            ));
            curl_exec($curl);
            curl_close($curl);
        }

        return null;
    }
}