<?php
/**
 * 이 파일은 아이모듈 회원모듈의 일부입니다. (https://www.imodules.io)
 *
 * 그룹정보를 저장한다.
 *
 * @file /modules/member/processes/group.post.php
 * @author Arzz <arzz@arzz.com>
 * @license MIT License
 * @modified 2024. 9. 10.
 *
 * @var \modules\member\Member $me
 */
if (defined('__IM_PROCESS__') == false) {
    exit();
}

/**
 * 관리자권한이 존재하는지 확인한다.
 */
if ($me->getAdmin()->checkPermission('members', ['groups']) == false) {
    $results->success = false;
    $results->message = $me->getErrorText('FORBIDDEN');
    return;
}

$group_id = Request::get('group_id');
if ($group_id !== null) {
    $group = $me
        ->db()
        ->select()
        ->from($me->table('groups'))
        ->where('group_id', $group_id)
        ->getOne();

    if ($group === null) {
        $results->success = false;
        $results->message = $me->getErrorText('NOT_FOUND_DATA');
        return;
    }
} else {
    $group = null;
}

$errors = [];
$parent_id = Input::get('parent_id') ?? 'all';
$title = Input::get('title', $errors) ?? 'noname';
$group_id = strlen(Input::get('group_id') ?? '') == 0 ? UUID::v1($title) : Input::get('group_id');
$manager = Input::get('manager') ?? $me->getText('group_manager');
$member = Input::get('member') ?? $me->getText('group_member');

$checked = $me
    ->db()
    ->select()
    ->from($me->table('groups'))
    ->where('group_id', $group_id);
if ($group !== null) {
    $checked->where('group_id', $group->group_id, '!=');
}
if ($checked->has() == true) {
    $errors['group_id'] = $me->getErrorText('DUPLICATED');
}

if ($title !== null) {
    $checked = $me
        ->db()
        ->select()
        ->from($me->table('groups'))
        ->where('title', $title);
    if ($group !== null) {
        $checked->where('group_id', $group->group_id, '!=');
    }
    if ($checked->has() == true) {
        $errors['title'] = $me->getErrorText('DUPLICATED');
    }
}

if (count($errors) == 0) {
    $parent_id = $parent_id == 'all' ? null : $parent_id;

    if ($group === null) {
        $me->db()
            ->insert($me->table('groups'), [
                'group_id' => $group_id,
                'parent_id' => $parent_id,
                'title' => $title,
                'manager' => $manager,
                'member' => $member,
            ])
            ->execute();
    } else {
        $me->db()
            ->update($me->table('groups'), [
                'group_id' => $group_id,
                'parent_id' => $parent_id,
                'title' => $title,
                'manager' => $manager,
                'member' => $member,
            ])
            ->where('group_id', $group->group_id)
            ->execute();

        if ($group->group_id !== $group_id) {
            $me->db()
                ->update($me->table('groups'), ['parent_id' => $group_id])
                ->where('parent_id', $group->group_id)
                ->execute();

            $me->db()
                ->update($me->table('group_members'), ['group_id' => $group_id])
                ->where('group_id', $group->group_id)
                ->execute();
        }

        // 상위그룹이 변경된 경우 현재 그룹에 속한 그룹구성원을 변경된 상위그룹에도 추가한다.
        if ($group->parent_id !== $parent_id) {
            $members = $me
                ->db()
                ->select()
                ->from($me->table('group_members'))
                ->where('group_id', $group_id)
                ->get('member_id');
            foreach ($members as $member_id) {
                $me->getGroup($group_id)->assignMember($member_id);
            }
        }
    }

    $results->success = true;
    $results->group_id = $group_id;
} else {
    $results->success = false;
    $results->errors = $errors;
}
