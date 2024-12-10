<?php
/**
 * 이 파일은 아이모듈 회원모듈의 일부입니다. (https://www.imodules.io)
 *
 * 모듈관리자 클래스를 정의한다.
 *
 * @file /modules/member/admin/Member.php
 * @author Arzz <arzz@arzz.com>
 * @license MIT License
 * @modified 2024. 12. 10.
 */
namespace modules\member\admin;
class Member extends \modules\admin\admin\Component
{
    /**
     * 관리자 컨텍스트 목록을 가져온다.
     *
     * @return \modules\admin\dtos\Context[] $contexts
     */
    public function getContexts(): array
    {
        $contexts = [];

        if ($this->hasPermission('members') == true) {
            $contexts[] = \modules\admin\dtos\Context::init($this)
                ->setContext('members')
                ->setTitle($this->getText('admin.contexts.members'), 'xi xi-users');
        }

        if ($this->getAdministrator()?->isMaster() === true) {
            $contexts[] = \modules\admin\dtos\Context::init($this)
                ->setContext('oauth')
                ->setTitle($this->getText('admin.contexts.oauth'), 'xi xi-user-lock');
        }

        return $contexts;
    }

    /**
     * 현재 모듈의 관리자 컨텍스트를 가져온다.
     *
     * @param string $path 컨텍스트 경로
     * @return string $html
     */
    public function getContext(string $path): string
    {
        switch ($path) {
            case 'members':
                \Html::script($this->getBase() . '/scripts/contexts/members.js');
                break;

            case 'oauth':
                \Html::script($this->getBase() . '/scripts/contexts/oauth.js');
                break;
        }

        return '';
    }

    /**
     * 현재 컴포넌트의 관리자 권한범위를 가져온다.
     *
     * @return \modules\admin\dtos\Scope[] $scopes
     */
    public function getScopes(): array
    {
        $scopes = [];

        $scopes[] = \modules\admin\dtos\Scope::init($this)
            ->setScope('members', $this->getText('admin.scopes.members.title'))
            ->addChild('view', $this->getText('admin.scopes.members.view'))
            ->addChild('edit', $this->getText('admin.scopes.members.edit'))
            ->addChild('groups', $this->getText('admin.scopes.members.groups'))
            ->addChild('levels', $this->getText('admin.scopes.members.levels'))
            ->addChild('logs', $this->getText('admin.scopes.members.logs'));

        return $this->setScopes($scopes);
    }

    /**
     * 전체 회원그룹을 가져온다.
     *
     * @param ?string $parent_id 부모고유값
     * @param ?string $keyword 키워드검색
     * @param ?int $limit 가져올깊이 (NULL 인 경우 제한없음, -1 인 경우 자동으로 깊이제한을 계산한다.)
     * @return array $tree
     */
    public function getGroupTree(?string $parent_id = null, ?string $keyword = null, ?int $limit = -1): array|bool
    {
        if ($keyword !== null) {
            $limit = null;
        } elseif ($limit === -1) {
            $tree = $this->db()
                ->select(['depth', 'count(*) as count'])
                ->from($this->table('groups'))
                ->groupBy('depth')
                ->orderBy('depth', 'asc')
                ->get();
            $limit = null;
            foreach ($tree as $depth) {
                if ($depth->count > 100) {
                    $limit = $depth->depth;
                    break;
                }
            }
        }

        $parent = null;
        if ($parent_id !== null) {
            /**
             * @var \modules\member\Member $mMember
             */
            $mMember = $this->getComponent();
            $parent = $mMember->getGroup($parent_id);
        }

        $groups = $this->db()
            ->select()
            ->from($this->table('groups'))
            ->where('parent_id', $parent_id)
            ->get();

        if ($parent !== null && $limit !== null && $parent->getDepth() >= $limit) {
            return count($groups) > 0 ? true : false;
        }

        $sort = 0;
        $tree = [];
        foreach ($groups as &$group) {
            $group->sort = $sort++;
            $group->children = $this->getGroupTree($group->group_id, $keyword, $limit);

            if (
                $keyword === null ||
                strpos(\Format::keycode($group->title), \Format::keycode($keyword)) !== false ||
                $group->children !== false
            ) {
                $tree[] = $group;
            }
        }

        return count($tree) == 0 ? false : $tree;
    }

    /**
     * 그룹을 삭제한다.
     *
     * @param string $group_id 삭제할 그룹고유값
     */
    public function deleteGroup(string $group_id): void
    {
        $group = $this->db()
            ->select()
            ->from($this->table('groups'))
            ->where('group_id', $group_id)
            ->getOne();

        if ($group === null) {
            return;
        }

        $this->db()
            ->delete($this->table('groups'))
            ->where('group_id', $group_id)
            ->execute();

        $this->db()
            ->delete($this->table('group_members'))
            ->where('group_id', $group_id)
            ->execute();

        foreach (
            $this->db()
                ->select(['group_id'])
                ->from($this->table('groups'))
                ->where('parent_id', $group_id)
                ->get('group_id')
            as $child
        ) {
            $this->deleteGroup($child);
        }
    }

    /**
     * OAuth 클라이언트 프리셋 목록을 가져온다.
     *
     * @return array $presets
     */
    public function getOAuthClientPresets(): array
    {
        $presets = [];

        $google = new \stdClass();
        $google->oauth_id = 'google';
        $google->auth_url = 'https://accounts.google.com/o/oauth2/v2/auth';
        $google->token_url = 'https://oauth2.googleapis.com/token';
        $google->scope = [
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
        ];
        $google->scope_separator = 'SPACE';
        $google->user_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
        $google->user_id_path = 'id';
        $google->user_email_path = 'email';
        $google->user_name_path = 'name';
        $google->user_nickname_path = 'nickname';
        $google->user_photo_path = 'picture';
        $presets[] = $google;

        $salesforce = new \stdClass();
        $salesforce->oauth_id = 'salesforce';
        $salesforce->auth_url = 'https://[YOUR_DOMAIN].my.salesforce.com/services/oauth2/authorize';
        $salesforce->token_url = 'https://[YOUR_DOMAIN].my.salesforce.com/services/oauth2/token';
        $salesforce->scope = ['chatter_api'];
        $salesforce->scope_separator = 'SPACE';
        $salesforce->user_url = 'https://[YOUR_DOMAIN].my.salesforce.com/services/data/v59.0/chatter/users/me';
        $salesforce->user_id_path = 'id';
        $salesforce->user_email_path = 'email';
        $salesforce->user_name_path = 'name';
        $salesforce->user_nickname_path = 'displayName';
        $salesforce->user_photo_path = 'photo.fullEmailPhotoUrl';
        $presets[] = $salesforce;

        $slack = new \stdClass();
        $slack->oauth_id = 'slack';
        $slack->auth_url = 'https://slack.com/oauth/v2/authorize';
        $slack->token_url = 'https://slack.com/api/oauth.v2.access';
        $slack->scope = ['users.profile:read'];
        $slack->scope_separator = ',';
        $slack->user_url = 'https://slack.com/api/users.profile.get';
        $slack->user_id_path = 'user_id';
        $slack->user_email_path = 'email';
        $slack->user_name_path = 'real_name';
        $slack->user_nickname_path = 'display_name.name';
        $slack->user_photo_path = 'image_512';
        $presets[] = $slack;

        return $presets;
    }
}
