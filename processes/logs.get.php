<?php
/**
 * 이 파일은 아이모듈 회원모듈의 일부입니다. (https://www.imodules.io)
 *
 * 관리자접속기록을 가져온다.
 *
 * @file /modules/member/processes/logs.get.php
 * @author Arzz <arzz@arzz.com>
 * @license MIT License
 * @modified 2024. 1. 26.
 *
 * @var \modules\member\Member $me
 */
if (defined('__IM_PROCESS__') == false) {
    exit();
}

/**
 * 관리자권한이 존재하는지 확인한다.
 */
if ($me->getAdmin()->checkPermission('members', ['logs']) == false) {
    $results->success = false;
    $results->message = $me->getErrorText('FORBIDDEN');
    return;
}

$sorters = Request::getJson('sorters');
$start = Request::getInt('start') ?? 0;
$limit = Request::getInt('limit') ?? 50;
$keyword = Request::get('keyword');

$records = $me
    ->db()
    ->select([
        'l.time',
        'l.member_id',
        'l.component_type',
        'l.component_name',
        'l.log_type',
        'l.ip',
        'm.name',
        'm.email',
    ])
    ->from($me->table('logs'), 'l')
    ->join($me->table('members'), 'm', 'm.member_id=l.member_id', 'LEFT');
$total = $records->copy()->count();
if ($sorters !== null) {
    foreach ($sorters as $field => $direction) {
        $records->orderBy('l.' . $field, $direction);
    }
}
$records = $records->limit($start, $limit)->get();
foreach ($records as &$record) {
    $record->component = null;
    if ($record->component_type == 'module') {
        $component = \Modules::get($record->component_name);
        $record->component = $component?->getIcon() ?? '';
        $record->component .= $component?->getTitle() ?? '';
    }

    $record->photo = $me->getMemberPhoto($record->member_id);
}

$results->success = true;
$results->records = $records;
$results->total = $total;
