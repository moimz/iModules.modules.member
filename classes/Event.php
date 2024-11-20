<?php
/**
 * 이 파일은 멤버 모듈의 일부입니다. (https://www.coursemos.co.kr)
 *
 * 멤버모듈의 이벤트를 정의한다.
 *
 * @file /modules/member/classes/Event.php
 * @author pbj <ju318@ubion.co.kr>
 * @license MIT License
 * @modified 2024. 11. 20.
 *
 */
namespace modules\member;
class Event extends \Event
{
    /**
     * @param $member_id
     * @return void
     */
    public static function beforeAdminLayout($member_id): bool|null
    {
        return null;
    }
}
