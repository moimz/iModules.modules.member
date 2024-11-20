<?php
/**
 * 이 파일은 아이모듈 회원모듈의 일부입니다. (https://www.imodules.io)
 *
 * 회원모듈 클래스 정의한다.
 *
 * @file /modules/member/Member.php
 * @author youlapark <youlapark@naddle.net>
 * @license MIT License
 * @modified 2024. 11. 20.
 */
namespace modules\member;
class Member extends \Module
{
    /**
     * @var \modules\member\Member[] $_members 회원정보
     */
    private static array $_members;

    /**
     * @var \modules\member\dtos\Group[] $_groups 그룹정보
     */
    private static array $_groups;

    /**
     * @var \modules\member\dtos\Level[] $_levels 레벨정보
     */
    private static array $_levels;

    /**
     * @var ?int $_logged 로그인정보
     */
    private static ?int $_logged = null;

    /**
     * 모듈을 설정을 초기화한다.
     */
    public function init(): void
    {
        /**
         * 모듈 라우터를 초기화한다.
         */
        \Router::add('/members/{member_id}/{type}', '#', 'blob', [$this, 'doRoute']);

        if ($this->isLogged() === false) {
            $this->loginByAutoLogin();
        }
    }

    /**
     * 컨텍스트를 가져온다.
     *
     * @param string $context 컨텍스트명
     * @param ?object $configs 컨텍스트 설정
     * @return string $html
     */
    public function getContext(string $context, ?object $configs = null): string
    {
        /**
         * 컨텍스트 템플릿을 설정한다.
         */
        if (isset($configs?->template) == true && $configs->template->name !== '#') {
            $this->setTemplate($configs->template);
        } else {
            $this->setTemplate($this->getConfigs('template'));
        }

        $content = '';
        switch ($context) {
            case 'edit':
                $content = $this->getEditContext($configs);
                break;

            default:
                $content = \ErrorHandler::get(\ErrorHandler::error('NOT_FOUND_URL'));
        }

        return $this->getTemplate()->getLayout($content);
    }

    /**
     * 회원정보수정 컨텍스트를 가져온다.
     *
     * @param object $configs
     * @return string $html
     */
    public function getEditContext($configs = null): string
    {
        \Html::color('light');

        /**
         * 현재 로그인한 상태일 경우 컨텍스트를 가져온다.
         */
        if ($this->isLogged() == true) {
            $member = $this->getMember($this->getLogged());
            if ($member === null) {
                return \ErrorHandler::get(\ErrorHandler::error('NOT_FOUND_URL'));
            }

            /**
             * 컨텍스트 템플릿을 설정한다.
             */
            if (isset($configs?->template) == true && $configs->template->name !== '#') {
                $this->setTemplate($configs->template);
            } else {
                $this->setTemplate($this->getConfigs('template'));
            }

            $template = $this->getTemplate();
            $template->assign('member', $member);

            $photo = $this->getPhoto();
            $template->assign('photo', $photo);

            $header = '<link href="/modules/member/styles/cropper.min.css" rel="stylesheet">';
            $footer = '<script src="/modules/member/scripts/cropper.min.js"></script>';

            return $template->getContext('edit', $header, $footer);
        } else {
            return \ErrorHandler::get(\ErrorHandler::error('NOT_FOUND_URL'));
        }
    }

    /**
     * 현재 로그인중인 회원고유번호를 가져온다.
     *
     * @return int $member_id 회원고유값 (로그인되어 있지 않은 경우 0)
     */
    public function getLogged(): int
    {
        if (self::$_logged === null) {
            $logged = \Request::session('MODULE_MEMBER_LOGGED');
            $logged = $logged !== null ? json_decode($logged) : null;
            self::$_logged = $logged?->member_id ?? null;
        }

        return self::$_logged ?? 0;
    }

    /**
     * 현재 사용자가 로그인중인지 확인한다.
     *
     * @return bool $is_logged
     */
    public function isLogged(): bool
    {
        return $this->getLogged() > 0;
    }

    /**
     * 패스워드 검증이 된 경우 30분 이내 패스워드 재검증을 하지 않기 위해 검증상태를 기억한다.
     *
     * @param bool $is_confirmed 검증여부 (기본값 : true)
     */
    public function setPasswordConfirmed(bool $is_confirmed = true): void
    {
        $_SESSION['MODULE_MEMBER_PASSWORD_CONFIRMED'] = $is_confirmed === true ? time() : 0;
    }

    /**
     * 패스워드 검증이 되어 있는 상태인지 확인한다.
     *
     * @return bool $confirmed
     */
    public function isPasswordConfirmed(): bool
    {
        $confirmed = \Request::session('MODULE_MEMBER_PASSWORD_CONFIRMED') ?? 0;

        return $confirmed > time() - 60 * 30;
    }

    /**
     * 그룹정보를 가져온다.
     *
     * @param string $group_id 그룹고유값
     * @return \modules\member\dtos\Group $group
     */
    public function getGroup(string $group_id): ?\modules\member\dtos\Group
    {
        if (isset(self::$_groups[$group_id]) == true) {
            return self::$_groups[$group_id];
        }

        $group = $this->db()
            ->select()
            ->from($this->table('groups'))
            ->where('group_id', $group_id)
            ->getOne();
        if ($group == null) {
            return null;
        }

        self::$_groups[$group_id] = new \modules\member\dtos\Group($group);
        return self::$_groups[$group_id];
    }

    /**
     * 전체 회원그룹을 가져온다.
     *
     * @param string $mode 가져올 방식 (tree, list)
     * @return array $groups
     */
    public function getGroups(string $mode = 'tree'): array
    {
        $groups = $this->db()
            ->select()
            ->from($this->table('groups'))
            ->where('parent', null)
            ->get();

        $sort = 0;
        if ($mode == 'tree') {
            foreach ($groups as &$group) {
                $group->sort = $sort++;
                $group->children = $this->getGroupChildren($group, $mode, $sort);
                if (count($group->children) == 0) {
                    unset($group->children);
                }
            }

            return $groups;
        } else {
            $items = [];
            foreach ($groups as $group) {
                $group->sort = $sort++;
                $items[] = $group;
                $items = [...$items, ...$this->getGroupChildren($group, $mode, $sort)];
            }

            return $items;
        }
    }

    /**
     * 특정 그룹의 자식그룹목록을 재귀적으로 가져온다.
     *
     * @param string $mode 가져올 방식 (tree, list)
     * @param object $parent 부모그룹
     * @param int $sort 정렬순서
     * @return array $children
     */
    private function getGroupChildren(object $parent, string $mode = 'tree', int &$sort = 0): array
    {
        $children = $this->db()
            ->select()
            ->from($this->table('groups'))
            ->where('parent', $parent->group_id)
            ->get();

        if ($mode == 'tree') {
            foreach ($children as &$child) {
                $child->sort = $sort++;
                $child->children = $this->getGroupChildren($child, $mode, $sort);
                if (count($child->children) == 0) {
                    unset($child->children);
                }
            }

            return $children;
        } else {
            $items = [];
            foreach ($children as $child) {
                $child->sort = $sort++;
                $items[] = $child;
                $items = [...$items, ...$this->getGroupChildren($child, $mode, $sort)];
            }

            return $items;
        }
    }

    /**
     * 레벨정보를 가져온다.
     *
     * @param int $level_id 레벨고유값
     * @return /\modules\member\dtos\Level $level
     */
    public function getLevel(int $level_id): ?\modules\member\dtos\Level
    {
        if (isset(self::$_levels[$level_id]) == true) {
            return self::$_levels[$level_id];
        }

        $level = $this->db()
            ->select()
            ->from($this->table('levels'))
            ->where('level_id', $level_id)
            ->getOne();

        if ($level == null) {
            return null;
        }

        self::$_levels[$level_id] = new \modules\member\dtos\Level($level);
        return self::$_levels[$level_id];
    }

    /**
     * 회원정보를 가져온다.
     *
     * @param ?int $member_id 회원고유값
     * @return \modules\member\dtos\Member $member
     */
    public function getMember(?int $member_id = null): \modules\member\dtos\Member
    {
        $member_id ??= $this->getLogged();
        if ($member_id !== 0 && isset(self::$_members[$member_id]) == true) {
            return self::$_members[$member_id];
        }

        if ($member_id === 0) {
            return new \modules\member\dtos\Member();
        }

        $member = $this->db()
            ->select()
            ->from($this->table('members'))
            ->where('member_id', $member_id)
            ->getOne();

        self::$_members[$member_id] = new \modules\member\dtos\Member($member);
        return self::$_members[$member_id];
    }

    /**
     * 회원사진 URL 을 가져온다.
     *
     * @param ?int $member_id 회원고유값 (NULL 인 경우 현재 로그인한 사용자)
     * @param bool $is_full_url 도메인을 포함한 전체 URL 여부
     * @return string $url
     */
    public function getMemberPhoto(?int $member_id = null, bool $is_full_url = false): string
    {
        $member_id ??= $this->getLogged();
        if ($member_id === 0) {
            return $this->getDir() . '/images/photo.jpg';
        }

        return \iModules::getUrl($is_full_url) . '/members/' . $member_id . '/photo.jpg';
    }

    /**
     * 회원사진 URL 을 가져온다.
     *
     * @param ?int $member_id 회원고유값 (NULL 인 경우 현재 로그인한 사용자)
     * @param bool $is_full_url 도메인을 포함한 전체 URL 여부
     * @return string $url
     */
    public function getPhoto(?int $member_id = null, bool $is_full_url = false): string
    {
        $member_id ??= $this->getLogged();
        if ($member_id === 0) {
            return $this->getDir() . '/images/photo.webp';
        }

        return '/attachments/member/photos/' . $member_id . '.webp';
    }

    /**
     * OAuth 클라이언트정보를 가져온다.
     *
     * @param string $oauth_id
     * @return ?\modules\member\dtos\OAuthClient $client
     */
    public function getOAuthClient(string $oauth_id): ?\modules\member\dtos\OAuthClient
    {
        $client = $this->db()
            ->select()
            ->from($this->table('oauth_clients'))
            ->where('oauth_id', $oauth_id)
            ->getOne();
        if ($client === null) {
            return null;
        }

        $client = new \modules\member\dtos\OAuthClient($client);

        return $client;
    }

    /**
     * 이메일 및 패스워드로 로그인을 처리한다.
     *
     * @param string $email 이메일주소
     * @param string $password 패스워드
     * @param bool $auto_login 로그인 기억여부
     * @return bool|string $success 성공여부 (TRUE 가 아닌 경우 로그인에러메시지를 반환한다.)
     */
    public function login(string $email, string $password, bool $auto_login = false): bool|string
    {
        $is_locked = \Request::session('MODULE_MEMBER_LOGIN_LOCKED') ?? 0;
        if ($is_locked > time()) {
            $this->storeLog($this, 'login', 'lock', ['locked_at' => $is_locked - 600], 0);
            return $this->getErrorText('LOGIN_LOCKED', ['second' => $is_locked - time()]);
        }

        $attempts = \Request::session('MODULE_MEMBER_LOGIN_ATTEMPTS') ?? 0;
        $member = $this->db()
            ->select(['member_id', 'password'])
            ->from($this->table('members'))
            ->where('email', $email)
            ->getOne();

        $success = true;
        if ($member === null) {
            $success = $this->getErrorText('LOGIN_FAILED');
        } else {
            if (\Password::verify($password, $member->password) === false) {
                $success = $this->getErrorText('LOGIN_FAILED');
            }
        }

        if ($success === true) {
            $member_id = $member->member_id;
            $ip = \Format::ip();
            $time = time();

            \Request::setSession('MODULE_MEMBER_LOGIN_ATTEMPTS', null);
            \Request::setSession('MODULE_MEMBER_LOGIN_LOCKED', null);

            if ($auto_login === true) {
                $login_id = \UUID::v1($email);
                $this->db()
                    ->insert($this->table('logins'), [
                        'login_id' => $login_id,
                        'member_id' => $member_id,
                        'created_at' => $time,
                        'created_ip' => $ip,
                        'logged_at' => $time,
                        'logged_ip' => $ip,
                        'agent' => \Format::agent(),
                    ])
                    ->execute();

                \Request::setCookie('MODULE_MEMBER_AUTO_LOGIN_ID', \Password::encode($login_id), 60 * 60 * 24 * 365);
            }

            $this->setPasswordConfirmed();

            return $this->loginTo($member_id);
        } else {
            /**
             * 로그인 실패시 실패 기록을 남긴다.
             * @todo 환경설정
             */
            \Request::setSession('MODULE_MEMBER_LOGIN_ATTEMPTS', ++$attempts);
            if ($attempts >= 5) {
                \Request::setSession('MODULE_MEMBER_LOGIN_LOCKED', time() + 600);
            }

            $this->storeLog(
                $this,
                'login',
                'fail',
                ['email' => $email, 'password' => $password, 'attempts' => $attempts],
                $member?->member_id ?? 0
            );
        }

        return $success;
    }

    /**
     * OAuth 클라이언트를 통해 로그인을 처리한다.
     *
     * @param \modules\member\dtos\OAuthAccount $account OAuth계정 객체
     */
    public function loginByOAuth(\modules\member\dtos\OAuthAccount $account): void
    {
        if ($account->isValid() == false) {
            \ErrorHandler::print($this->error('OAUTH_ACCESS_TOKEN_FAILED'));
        }

        $member_ids = $account->getLinkedMemberIds();

        /**
         * 현재 로그인상태여부에 따라 OAuth 로그인을 처리한다.
         */
        if ($this->isLogged() == true) {
            if (count($member_ids) === 0) {
                /**
                 * OAuth 계정이 회원계정과 연동되어 있지 않은 경우,
                 * 패스워드 검증을 하거나, 검증이 되어 있다면 바로 연동을 처리한다.
                 */
                if ($this->isPasswordConfirmed() === true) {
                    $success = $this->getMember()->setOAuthAccount($account);
                    if ($success == true) {
                        $account->getOAuth()->redirect();
                    } else {
                        \ErrorHandler::print($this->error('OAUTH_ACCESS_TOKEN_FAILED'));
                    }
                } else {
                    $this->printOAuthLinkComponent('password', $account);
                }
            } else {
                /**
                 * 현재 로그인한 계정과, 다른 계정으로 OAuth 계정이 연동된 경우 에러메시지를 출력한다.
                 */
                if (in_array($this->getLogged(), $member_ids) == false) {
                    \ErrorHandler::print($this->error('OAUTH_LINKED_OTHER_ACCOUNT'));
                }

                $success = $this->getMember()->setOAuthAccount($account);
                if ($success == true) {
                    $account->getOAuth()->redirect();
                } else {
                    \ErrorHandler::print($this->error('OAUTH_ACCESS_TOKEN_FAILED'));
                }
            }
        } else {
            /**
             * OAuth 계정으로 회원고유값을 파악하지 못한 경우 (첫 연동인 경우)
             */
            if (count($member_ids) === 0) {
                $this->printOAuthLinkComponent('link', $account);
            } else {
                /**
                 * OAuth 계정과 연동된 회원계정이 다수인 경우
                 */
                if (count($member_ids) > 1) {
                    $this->printOAuthLinkComponent('select', $account);
                } else {
                    $success = $this->getMember($member_ids[0])->setOAuthAccount($account);
                    if ($success == true) {
                        $account->getOAuth()->redirect();
                    } else {
                        \ErrorHandler::print($this->error('OAUTH_ACCESS_TOKEN_FAILED'));
                    }
                }
            }
        }

        exit();
    }

    /**
     * 자동로그인을 통해 로그인한다.
     */
    private function loginByAutoLogin(): void
    {
        $login_id = \Request::cookie('MODULE_MEMBER_AUTO_LOGIN_ID');
        if ($login_id === null) {
            return;
        }

        $login_id = \Password::decode($login_id);
        if ($login_id === false) {
            return;
        }

        $login = $this->db()
            ->select()
            ->from($this->table('logins'))
            ->where('login_id', $login_id)
            ->getOne();
        if ($login === null) {
            return;
        }

        $this->db()
            ->update($this->table('logins'), [
                'logged_at' => time(),
                'logged_ip' => \Format::ip(),
                'agent' => \Format::agent(),
            ])
            ->where('login_id', $login_id)
            ->execute();

        $member_id = $login->member_id;
        $ip = \Format::ip();
        $time = time();

        $this->loginTo($member_id, false);

        $this->db()
            ->update($this->table('members'), ['logged_at' => $time, 'logged_ip' => $ip])
            ->where('member_id', $member_id)
            ->execute();
        $this->storeLog($this, 'login', 'success', ['login_id' => $login_id]);
    }

    /**
     * 특정 회원으로 로그인한다.
     *
     * @param int $member_id 로그인할 회원고유값
     * @param bool $log 로그기록여부
     * @return bool $success
     */
    public function loginTo(int $member_id, bool $log = true): bool
    {
        $ip = \Format::ip();
        $time = time();

        \Request::setSession(
            'MODULE_MEMBER_LOGGED',
            json_encode([
                'member_id' => $member_id,
                'ip' => $ip,
                'time' => $time,
            ])
        );

        self::$_logged = $member_id;

        if ($log === true) {
            $this->db()
                ->update($this->table('members'), ['logged_at' => $time, 'logged_ip' => $ip])
                ->where('member_id', $member_id)
                ->execute();
            $this->storeLog($this, 'login', 'success');
        }

        //@todo $oauth : 예외처리, 위치 조정
        $oauth = \Events::fireEvent($this, 'beforeAdminLayout', [$member_id], 'NOTNULL');

        return true;
    }

    /**
     * 로그아웃한다.
     */
    public function logout(): void
    {
        if ($this->isLogged() == true) {
            $login_id = \Request::cookie('MODULE_MEMBER_AUTO_LOGIN_ID');
            if ($login_id !== null) {
                $login_id = \Password::decode($login_id);
                if ($login_id === false) {
                    $login_id = null;
                }
            }

            self::$_logged = null;
            \Request::setSession('MODULE_MEMBER_LOGGED', null);

            if ($login_id !== null) {
                $this->db()
                    ->delete($this->table('logins'))
                    ->where('login_id', $login_id)
                    ->execute();
            }
            \Request::setCookie('MODULE_MEMBER_AUTO_LOGIN_ID', null);
            $this->setPasswordConfirmed(false);
            $this->storeLog($this, 'logout', 'success', ['auto_login' => $login_id]);
        }
    }

    /**
     * 로그를 기록한다.
     *
     * @param \Component $component 로그기록을 요청한 컴포넌트객체
     * @param string $log_type 로그타입
     * @param string|int $log_id 로그고유값
     * @param string $details 상세기록
     * @param ?int $member_id 회원고유값 (NULL인 경우 현재 로그인한 사용자)
     * @return bool $success
     */
    public function storeLog(
        \Component $component,
        string $log_type,
        string|int $log_id,
        string|array $details = null,
        ?int $member_id = null
    ): bool {
        $member_id ??= $this->getLogged();
        $details = is_array($details) == true ? \Format::toJson($details) : $details;

        $this->db()
            ->replace($this->table('logs'), [
                'time' => \Format::microtime(3),
                'member_id' => $member_id,
                'component_type' => $component->getType(),
                'component_name' => $component->getName(),
                'log_type' => $log_type,
                'log_id' => $log_id,
                'details' => $details,
                'ip' => \Format::ip(),
                'agent' => \Format::agent(),
            ])
            ->execute();
        return $this->db()->getLastError() === null;
    }

    /**
     * 회원을 추가한다.
     *
     * @param array $member 회원정보 배열 (필드명 => 값)
     * @return int|array|bool $member_id 추가된 회원의 고유값 (array 인 경우 에러필드 정보, false 인 경우 추가실패)
     */
    public function addMember(array $member): int|array|bool
    {
        $requires = ['email', 'password', 'nickname'];
        foreach ($requires as $require) {
            if (array_key_exists($require, $member) == false) {
                return false;
            }
        }

        $errors = [];
        if (\Format::checkEmail($member['email']) == false) {
            $errors['email'] = $this->getErrorText('INVALID_EMAIL');
        }
        if (isset($errors['email']) == false) {
            if ($this->hasActiveMember('email', $member['email']) == true) {
                $errors['email'] = $this->getErrorText('DUPLICATED');
            }
        }

        if (\Format::checkNickname($member['nickname']) == false) {
            $errors['nickname'] = $this->getErrorText('INVALID_NICKNAME');
        }
        if (isset($errors['nickname']) == false) {
            if ($this->hasActiveMember('nickname', $member['nickname']) == true) {
                $errors['nickname'] = $this->getErrorText('DUPLICATED');
            }
        }

        if (array_key_exists('name', $member) == false) {
            $member['name'] = $member['nickname'];
        }

        if (array_key_exists('level_id', $member) == false) {
            $member['level_id'] = $member['level_id'];
        } else {
            $member['level_id'] = 0;
        }

        if (array_key_exists('joined_at', $member) == false) {
            $member['joined_at'] = time();
        }

        if (array_key_exists('status', $member) == false) {
            // @todo 기본값 확인
            $member['status'] = 'ACTIVATED';
        }

        if (array_key_exists('verified', $member) == false) {
            // @todo 기본값 확인
            $member['verified'] = 'TRUE';
        }

        $member['password'] = \Password::hash($member['password']);

        $results = $this->db()
            ->insert($this->table('members'), $member)
            ->execute();

        $this->getLevel($member['level_id'])->update();

        return $results->insert_id > 0 ? $results->insert_id : false;
    }

    /**
     * 특정정보를 가진 활성화된 회원계정이 존재하는지 확인한다.
     *
     * @param string $field 중복체크할 필드명
     * @param string $value 중복체크할 값
     * @param int $exclude_member_id 검색에서 제외할 회원아이디
     * @return bool $duplicated 중복여부
     */
    public function hasActiveMember(string $field, string $value, int $exclude_member_id = 0): bool
    {
        $check = $this->db()
            ->select()
            ->from($this->table('members'))
            ->where($field, $value);
        if ($exclude_member_id > 0) {
            $check->where('member_id', $exclude_member_id, '!=');
        }

        return $check->has();
    }

    /**
     * OAuth 계정 연동을 위한 컴포넌트를 출력한다.
     *
     * @param string $type 컴포넌트타입
     * @param \modules\member\dtos\OAuthAccount $account OAuth 게정정보
     */
    public function printOAuthLinkComponent(string $type, \modules\member\dtos\OAuthAccount $account)
    {
        $template = $this->setTemplate($this->getConfigs('template'))->getTemplate();
        $template->assign('type', $type);
        $template->assign('account', $account);
        $template->assign('photo', $account->getPhoto() ?? $this->getMemberPhoto(0));

        $forms = [];

        $code = [
            'oauth_id' => $account->getClient()->getId(),
            'access_token' => $account->getAccessToken(),
            'access_token_expired_at' => $account->getAccessTokenExpiredAt(),
            'refresh_token' => $account->getRefreshToken(),
            'scope' => $account->getAccessTokenScope(),
            'user' => $account->getUser(),
        ];
        $code = \Password::encode(json_encode($code));

        switch ($type) {
            case 'password':
                $member = $this->getMember();

                $password = new \stdClass();
                $password->title = $this->getText('oauth.label.password');
                $password->element = \Html::tag(
                    \Html::element('input', ['type' => 'hidden', 'name' => 'mode', 'value' => 'password']),
                    \Html::element('input', ['type' => 'hidden', 'name' => 'code', 'value' => $code]),
                    \Form::input('mode', 'hidden')
                        ->value('password')
                        ->getLayout(),
                    \Form::input('password', 'password')
                        ->placeholder($this->getText('password'))
                        ->getLayout()
                );
                $password->buttonText = $this->getText('oauth.button.password');

                $forms[] = $password;
                break;

            case 'link':
                $member = $this->getMember($account->getSuggestedMemberId());

                $signup = new \stdClass();
                $signup->title = $this->getText('oauth.label.signup.signup');
                $signup->element = \Html::tag(
                    \Html::element('input', ['type' => 'hidden', 'name' => 'mode', 'value' => 'signup']),
                    \Html::element('input', ['type' => 'hidden', 'name' => 'code', 'value' => $code]),
                    \Form::input('email', 'email')
                        ->placeholder($this->getText('email'))
                        ->value($account->getEmail())
                        ->getLayout(),
                    \Form::input('password', 'text')
                        ->placeholder($this->getText('password'))
                        ->getLayout(),
                    \Form::input('nickname', 'text')
                        ->placeholder($this->getText('nickname'))
                        ->value($account->getNickname())
                        ->getLayout()
                );
                $signup->buttonText = $this->getText('oauth.button.signup');

                $login = new \stdClass();
                $login->title = $this->getText('oauth.label.signup.signup');
                $login->element = \Html::tag(
                    \Html::element('input', ['type' => 'hidden', 'name' => 'mode', 'value' => 'login']),
                    \Html::element('input', ['type' => 'hidden', 'name' => 'code', 'value' => $code]),
                    \Form::input('email', 'email')
                        ->placeholder($this->getText('email'))
                        ->value($member->getId() == 0 ? $account->getEmail() : $member->getEmail())
                        ->getLayout(),
                    \Form::input('password', 'password')
                        ->placeholder($this->getText('password'))
                        ->getLayout()
                );
                $login->buttonText = $this->getText('oauth.button.login');

                if ($member->getId() == 0 && $account->getClient()->isAllowSignup() == true) {
                    $signup->title = $this->getText('oauth.label.signup.signup');
                    $login->title = $this->getText('oauth.label.signup.login');
                    $forms = [$signup, $login];
                } else {
                    $login->title = $this->getText('oauth.label.login.login');
                    $signup->title = $this->getText('oauth.label.login.signup');

                    if ($account->getClient()->isAllowSignup() == true) {
                        $forms = [$login, $signup];
                    } else {
                        $forms = [$login];
                    }
                }
                break;

            default:
                $member = $this->getMember(0);
                $forms = [];
        }

        $template->assign('forms', $forms);
        $template->assign('member', $this->getMember($account->getSuggestedMemberId()));

        $attributes = [
            'data-role' => 'module',
            'data-module' => $this->getName(),
            'data-context' => 'oauth.link',
            'data-template' => $template->getName(),
        ];

        $content = \Html::element('div', $attributes, $template->getContext('oauth.link'));

        /**
         * 기본 리소스를 불러온다.
         */
        \iModules::resources();

        \Html::print(\Html::header(), $content, \Html::footer());
        exit();
    }

    /**
     * 회원관련 이미지 라우팅을 처리한다.
     *
     * @param Route $route 현재경로
     * @param int $member_id 회원고유값
     * @param string $type 이미지종류
     */
    public function doRoute(\Route $route, string $member_id, string $type): void
    {
        $temp = explode('.', $type);
        $type = $temp[0];
        $extension = $temp[1];

        if (in_array($type, ['photo', 'nickcon']) === false) {
            \ErrorHandler::print($this->error('NOT_FOUND_FILE', $route->getUrl()));
        }

        if (is_file(\Configs::attachment() . '/member/' . $type . 's/' . $member_id . '.' . $extension) == true) {
            $path = \Configs::attachment() . '/member/' . $type . 's/' . $member_id . '.' . $extension;
        } else {
            $path = $this->getPath() . '/images/' . $type . '.' . $extension;
        }

        \iModules::session_stop();

        \Header::type($extension);
        \Header::length(filesize($path));
        \Header::cache(3600);

        readfile($path);
        exit();
    }

    /**
     * 특수한 에러코드의 경우 에러데이터를 현재 클래스에서 처리하여 에러클래스로 전달한다.
     *
     * @param string $code 에러코드
     * @param ?string $message 에러메시지
     * @param ?object $details 에러와 관련된 추가정보
     * @return \ErrorData $error
     */
    public function error(string $code, ?string $message = null, ?object $details = null): \ErrorData
    {
        switch ($code) {
            case 'OAUTH_AUTHENTICATION_REQUEST_FAILED':
                $error = \ErrorHandler::data($code);
                $error->message = $this->getErrorText($code);
                $error->prefix = $details?->error ?? null;
                $error->suffix = $details?->description ?? null;
                return $error;

            default:
                return parent::error($code, $message, $details);
        }
    }

    /**
     * 회원모듈이 설치된 이후 최고관리자(회원고유값=1) 정보를 변경한다.
     *
     * @param string $previous 이전설치버전 (NULL 인 경우 신규설치)
     * @param object $configs 모듈설정
     * @return bool|string $success 설치성공여부
     */
    public function install(string $previous = null, object $configs = null): bool|string
    {
        $success = parent::install($previous, $configs);
        if ($success === true) {
            if (\File::createDirectory(\Configs::attachment() . '/member/photos', 0707) === false) {
                return $this->getErrorText('CREATE_DIRECTORY_FAILED', [
                    'name' => \Configs::attachment() . '/member/photos',
                ]);
            }
            if (\File::createDirectory(\Configs::attachment() . '/member/nickcons', 0707) === false) {
                return $this->getErrorText('CREATE_DIRECTORY_FAILED', [
                    'name' => \Configs::attachment() . '/member/nickcons',
                ]);
            }

            if (\Request::get('token') !== null) {
                $token = json_decode(\Password::decode(\Request::get('token')));
                if (
                    $token !== null &&
                    isset($token->admin_email) == true &&
                    isset($token->admin_password) == true &&
                    isset($token->admin_name) == true
                ) {
                    $this->db()
                        ->insert(
                            $this->table('members'),
                            [
                                'member_id' => 1,
                                'email' => $token->admin_email,
                                'name' => $token->admin_name,
                                'nickname' => $token->admin_name,
                                'password' => \Password::hash($token->admin_password),
                                'joined_at' => time(),
                            ],
                            ['email', 'password', 'name', 'nickname']
                        )
                        ->execute();

                    $this->db()
                        ->insert(
                            $this->table('levels'),
                            [
                                'level_id' => 0,
                                'title' => $this->getText('default_level'),
                                'members' => -1,
                            ],
                            ['members']
                        )
                        ->execute();
                }
            }
        }

        return $success;
    }
}
