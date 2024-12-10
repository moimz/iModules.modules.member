<?php
/**
 * 이 파일은 아이모듈 회원모듈의 일부입니다. (https://www.imodules.io)
 *
 * 회원정보를 저장한다.
 *
 * @file /modules/member/processes/member.post.php
 * @author youlapark <youlapark@naddle.net>
 * @license MIT License
 * @modified 2024. 12. 10.
 *
 * @var \modules\member\Member $me
 */
if (defined('__IM_PROCESS__') == false) {
    exit();
}

/**
 * 관리자권한이 존재하는지 확인한다.
 */
if ($me->getAdmin()->checkPermission('members', ['edit']) == false) {
    $results->success = false;
    $results->message = $me->getErrorText('FORBIDDEN');
    return;
}

$member_id = Request::get('member_id');
if ($member_id !== null) {
    $member = $me
        ->db()
        ->select()
        ->from($me->table('members'))
        ->where('member_id', $member_id)
        ->getOne();
    if ($member === null) {
        $results->success = false;
        $results->message = $me->getErrorText('NOT_FOUND_DATA');
        return;
    }
} else {
    $member = null;
}

$errors = [];
$email =
    Format::checkEmail(Input::get('email')) == true
        ? Input::get('email')
        : ($errors['email'] = $me->getErrorText('INVALID_EMAIL'));
if (isset($errors['email']) == false) {
    if ($me->hasActiveMember('email', $email, $member?->member_id ?? 0) == true) {
        $errors['email'] = $me->getErrorText('DUPLICATED');
    }
}

$name = Input::get('name', $errors);
$nickname =
    Format::checkNickname(Input::get('nickname')) == true
        ? Input::get('nickname')
        : ($errors['nickname'] = $me->getErrorText('INVALID_NICKNAME'));
if (isset($errors['nickname']) == false) {
    if ($me->hasActiveMember('nickname', $email, $member?->member_id ?? 0) == true) {
        $errors['nickname'] = $me->getErrorText('DUPLICATED');
    }
}

$photo = Input::get('photo');
if ($photo !== null) {
    $photo_decode = str_replace('data:image/png;base64,', '', $photo);
    $photo = base64_decode($photo_decode);

    /**
     * @var \modules\attachment\Attachment $mAttachment
     */
    $mAttachment = Modules::get('attachment');
    $path = \Configs::attachment() . '/member/photos/' . $member_id . '.webp';

    file_put_contents($path, $photo);
    @chmod($path, 0707);

    $mAttachment->createThumbnail($path, $path, 500, false, 'webp');
}

$cellphone = Input::get('cellphone');
if ($member === null) {
    $password = Input::get('password', $errors);
}

$level_id = Input::get('level_id') ?? 0;
if ($me->getLevel($level_id) === null) {
    $errors['level'] = $me->getErrorText('NOT_FOUND_DATA');
}

if (count($errors) == 0) {
    $insert = [];
    if ($member === null) {
        $insert['email'] = $email;
        $insert['password'] = $password;
        $insert['name'] = $name;
        $insert['nickname'] = $nickname;
        $insert['level_id'] = $level_id;
        $insert['verified'] = 'TRUE';
        $insert['status'] = 'ACTIVATED';
        $insert['joined_at'] = time();

        $member_id = $me->addMember($insert);
        if (is_int($member_id) == false) {
            $results->success = false;
            if (is_array($member_id) == true) {
                $results->errors = $member_id;
            }
            return;
        }
    } else {
        $insert['email'] = $email;
        if (Input::get('password') !== null) {
            $insert['password'] = \Password::hash(Input::get('password'));
        }
        $insert['name'] = $name;
        $insert['nickname'] = $nickname;
        $insert['level_id'] = $level_id;
        $insert['cellphone'] = $cellphone;

        $me->db()
            ->update($me->table('members'), $insert)
            ->where('member_id', $member->member_id)
            ->execute();

        if ($member->level_id !== $level_id) {
            $me->getLevel($member->level_id)->update();
            $me->getLevel($level_id)->update();
        }

        $member_id = $member->member_id;
    }

    $member = $me->getMember($member_id);
    $group_ids = [];
    foreach (Input::get('group_ids') ?? [] as $group_id) {
        $group = $me->getGroup($group_id);
        $group_ids[] = $group_id;
        $group_ids = array_merge($group_ids, $group->getParentIds());

        $group->assignMember($member_id);
    }

    foreach ($me->getMember($member_id)->getGroups() as $group) {
        if (in_array($group->getGroup()->getId(), $group_ids) == false) {
            $group->getGroup()->removeMember($member_id);
        }
    }

    /**
     * @var \modules\naddle\coursemos\Coursemos $mCoursemos
     */
    $mCoursemos = \Modules::get('naddle/coursemos');

    $oauth = $mCoursemos->getOauthManager($member_id);
    //모모->슬랙 기본프로필 싱크
    $oauth->setProfile();
    //모모->슬랙 프로필사진 싱크
    $oauth->setPhoto();

    $results->success = true;
    $results->member_id = $member_id;
} else {
    $results->success = false;
    $results->errors = $errors;
}
