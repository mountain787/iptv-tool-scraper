<?php
/**
 * @file scraper.php
 * @brief 各数据源抓取与解析处理函数集合
 *
 * 本文件定义了多个数据源的匹配规则及对应的抓取处理函数。
 * 通过统一的 `$sourceHandlers` 数组，方便根据 URL 选择合适的数据抓取逻辑。
 * 
 * 数据源项结构说明：
 * - match (callable): 接收 URL，判断是否匹配当前数据源，返回布尔值。
 * - handler (callable): 实际抓取并解析数据的函数，返回格式化后的频道节目数据。
 *
 * handler 返回数据结构示例：
 * [
 *   'channel_id' => [
 *       'channel_name'   => '频道名称',
 *       'diyp_data'      => [
 *           'YYYY-MM-DD' => [
 *               ['start' => 'HH:mm', 'end' => 'HH:mm', 'title' => '节目名', 'desc' => '简介'],
 *               ...
 *           ],
 *           ...
 *       ],
 *       'process_count'  => 整数，处理的节目条数
 *   ],
 *   ...
 * ]
 * 
 * 作者: Tak
 * GitHub: https://github.com/taksssss/iptv-tool
 */

$sourceHandlers = [

    // 示例：tvmao, 湖南卫视
    'tvmao' => [
        'match' => function ($url) { return stripos($url, 'tvmao') === 0; },
        'handler' => function ($url) { return tvmaoHandler($url); }
    ],

    // 示例：cntv:2, CCTV4欧洲:cctveurope
    'cntv' => [
        'match' => function ($url) { return stripos($url, 'cntv') === 0; },
        'handler' => function ($url) { return cntvHandler($url); }
    ],
    
    // 示例：chuan:2, CHC高清电影:3418
    'chuan' => [
        'match' => function ($url) { return stripos($url, 'chuan') === 0; },
        'handler' => function ($url) { return chuanHandler($url); }
    ],
    
    // 示例：twmod:3, 民視HD:005
    'twmod' => [
        'match' => function ($url) { return strpos($url, 'twmod:') === 0; },
        'handler' => function ($url) { return twmodHandler($url); }
    ],
];


// 引入额外来源配置
$customHandlers = include __DIR__ . '/data/customSource.php';
if (is_array($customHandlers)) {
    $sourceHandlers = array_merge($sourceHandlers, $customHandlers);
}

/**
 * TVMao 数据处理
 */
function tvmaoHandler($url) {
    $tvmaostr = str_ireplace('tvmao,', '', $url);
    
    $channelProgrammes = [];
    foreach (explode(',', $tvmaostr) as $tvmao_info) {
        list($channelName, $channelId) = array_map('trim', explode(':', trim($tvmao_info)) + [null, $tvmao_info]);
        $channelProgrammes[$channelId]['channel_name'] = cleanChannelName($channelName);

        $json_url = "https://sp0.baidu.com/8aQDcjqpAAV3otqbppnN2DJv/api.php?query={$channelId}&resource_id=12520&format=json";
        $json_data = safe_get_contents($json_url);
        $json_data = mb_convert_encoding($json_data, 'UTF-8', 'GBK');
        $data = json_decode($json_data, true);
        if (empty($data['data'])) {
            $channelProgrammes[$channelId]['process_count'] = 0;
            continue;
        }
        $data = $data['data'][0]['data'];
        $skipTime = null;
        foreach ($data as $epg) {
            if ($time_str = $epg['times'] ?? '') {
                $starttime = DateTime::createFromFormat('Y/m/d H:i', $time_str);
                $date = $starttime->format('Y-m-d');
                // 如果第一条数据早于今天 02:00，则认为今天的数据是齐全的
                if (is_null($skipTime)) {
                    $skipTime = $starttime < new DateTime("today 02:00") ? 
                                new DateTime("today 00:00") : new DateTime("tomorrow 00:00");
                }
                if ($starttime < $skipTime) continue;
                $channelProgrammes[$channelId]['diyp_data'][$date][] = [
                    'start' => $starttime->format('H:i'),
                    'end' => '',  // 初始为空
                    'title' => trim($epg['title']),
                    'desc' => ''
                ];
            }
        }
        // 填充 'end' 字段
        foreach ($channelProgrammes[$channelId]['diyp_data'] as $date => &$programmes) {
            foreach ($programmes as $i => &$programme) {
                $nextStart = $programmes[$i + 1]['start'] ?? '00:00';  // 下一个节目开始时间或 00:00
                $programme['end'] = $nextStart;  // 填充下一个节目的 'start'
                if ($nextStart === '00:00') {
                    // 尝试获取第二天数据并补充
                    $nextDate = (new DateTime($date))->modify('+1 day')->format('Y-m-d');
                    $nextDayProgrammes = $channelProgrammes[$channelId]['diyp_data'][$nextDate] ?? [];
                    if (!empty($nextDayProgrammes) && $nextDayProgrammes[0]['start'] !== '00:00') {
                        array_unshift($channelProgrammes[$channelId]['diyp_data'][$nextDate], [
                            'start' => '00:00',
                            'end' => '',
                            'title' => $programme['title'],
                            'desc' => ''
                        ]);
                    }
                }
            }
        }
        $channelProgrammes[$channelId]['process_count'] = count($data);
    }
    return $channelProgrammes;
}

/**
 * CNTV 数据处理
 */
function cntvHandler($url) {
    $date_range = 1;
    if (preg_match('/^cntv:(\d+),\s*(.*)$/i', $url, $matches)) {
        $date_range = $matches[1]; // 提取日期范围
        $cntvstr = $matches[2]; // 提取频道字符串
    } else {
        $cntvstr = str_ireplace('cntv,', '', $url); // 没有日期范围时去除 'cntv,'
    }
    $need_dates = array_map(function($i) { return (new DateTime())->modify("+$i day")->format('Ymd'); }, range(0, $date_range - 1));

    $channelProgrammes = [];
    foreach (explode(',', $cntvstr) as $cntv_info) {
        list($channelName, $channelId) = array_map('trim', explode(':', trim($cntv_info)) + [null, $cntv_info]);
        $channelId = strtolower($channelId);
        $channelProgrammes[$channelId]['channel_name'] = cleanChannelName($channelName);

        $processCount = 0;
        foreach ($need_dates as $need_date) {
            $json_url = "https://api.cntv.cn/epg/getEpgInfoByChannelNew?c={$channelId}&serviceId=tvcctv&d={$need_date}";
            $json_data = safe_get_contents($json_url);
            $data = json_decode($json_data, true);
            if (!isset($data['data'][$channelId]['list'])) {
                continue;
            }
            $data = $data['data'][$channelId]['list'];
            foreach ($data as $epg) {
                $starttime = (new DateTime())->setTimestamp($epg['startTime']);
                $endtime = (new DateTime())->setTimestamp($epg['endTime']);
                $date = $starttime->format('Y-m-d');
                $channelProgrammes[$channelId]['diyp_data'][$date][] = [
                    'start' => $starttime->format('H:i'),
                    'end' => $endtime->format('H:i'),
                    'title' => trim($epg['title']),
                    'desc' => ''
                ];
            }
            $processCount += count($data);
        }
        $channelProgrammes[$channelId]['process_count'] = $processCount;
    }

    return $channelProgrammes;
}



/**
 * 處理 '川流' 格式的節目單數據
 * 來源：http://epg.iqy.sc96655.com
 *
 * @param string $url 格式為 "chuan:天數,頻道名稱:頻道ID,..."
 * @return array 格式化後的節目單數據
 */
function chuanHandler($url) {
    $date_range = 1;
    $channels_str = '';

    // 使用正則表達式解析 URL，提取天數和頻道列表
    if (preg_match('/^chuan:(\d+),(.*)$/i', $url, $matches)) {
        $date_range = intval($matches[1]);
        $channels_str = trim($matches[2]);
    } else {
        // 格式不符，直接返回空陣列
        return [];
    }

    // 解析頻道列表
    $channel_list = [];
    $channel_list_raw = explode(',', $channels_str);
    foreach ($channel_list_raw as $channel_info) {
        $parts = explode(':', trim($channel_info));
        if (count($parts) === 2) {
            $channel_name = trim($parts[0]);
            $channel_id = trim($parts[1]);
            $channel_list[$channel_id] = [
                'name' => $channel_name,
                'id' => $channel_id
            ];
        }
    }

    if (empty($channel_list)) {
        return [];
    }

    // 生成需要獲取數據的日期陣列
    $need_dates = [];
    for ($i = 0; $i < $date_range; $i++) {
        $need_dates[] = (new DateTime())->modify("+$i day")->format('Y-m-d');
    }

    $channelProgrammes = [];

    foreach ($channel_list as $chn) {
        $chnId = $chn['id'];
        $channelProgrammes[$chnId] = [
            'channel_name' => cleanChannelName($chn['name']),
            'diyp_data' => [],
            'process_count' => 0
        ];

        foreach ($need_dates as $date) {
            // 構建 API 地址，並對日期和時間進行 URL 編碼
            $stTime = urlencode("$date 00:00:00");
            $endTime = urlencode("$date 23:59:59");
            $bstrURL = "http://epg.iqy.sc96655.com/v1/getPrograms?channel=$chnId&begin_time=$stTime&end_time=$endTime";

            // 使用 cURL 抓取資料
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $bstrURL);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                // 這是你提供的 Authorization Bearer Token
                "Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiI5ODQwODlhNjc1OGU0ZjJlOTViMjk4NWM4YjA1MDNmYiIsImNvbXBhbnkiOiJxaXlpIiwibmFtZSI6InRlcm1pbmFsIn0.1gDPpBcHJIE8dLiq7UekUlPWMtJOYymI8zoIYlsVgc4"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $json_data = curl_exec($ch);
            curl_close($ch);
            
            // 檢查 cURL 請求是否成功
            if ($json_data === false) {
                continue;
            }

            $data = json_decode($json_data, true);

            // 檢查 JSON 解析和 API 返回狀態
            if (isset($data['ret_status']) && $data['ret_status'] === 0 && isset($data['ret_data'])) {
                foreach ($data['ret_data'] as $epg) {
                    if (isset($epg['begin_time'], $epg['end_time'], $epg['name'])) {
                        $begin_time = new DateTime($epg['begin_time']);
                        $end_time = new DateTime($epg['end_time']);
                        $epg_date = $begin_time->format('Y-m-d');

                        $channelProgrammes[$chnId]['diyp_data'][$epg_date][] = [
                            'start' => $begin_time->format('H:i'),
                            'end' => $end_time->format('H:i'),
                            'title' => trim($epg['name']),
                            'desc' => trim($epg['desc'] ?? '')
                        ];
                        $channelProgrammes[$chnId]['process_count']++;
                    }
                }
            }
        }
    }

    return $channelProgrammes;
}
/**
 * 處理 '中华电信' 格式的節目單數據
 * 來源：https://mod.cht.com.tw
 *
 * @param string $url 格式為 "twmod:天數,頻道名稱:頻道ID,..."
 * @return array 格式化後的節目單數據
 */

function twmodHandler($url) {
    $date_range = 1;
    if (preg_match('/^twmod:(\d+),\s*(.*)$/i', $url, $matches)) {
        $date_range = intval($matches[1]); // 提取天数
        $twstr = $matches[2];
    } else {
        $twstr = preg_replace('/^twmod,/', '', $url);
    }

    $need_dates = array_map(function($i) { 
        return (new DateTime())->modify("+$i day")->format('Y-m-d'); 
    }, range(0, $date_range - 1));

    $channelProgrammes = [];
    foreach (explode(',', $twstr) as $tw_info) {
        list($channelName, $idPart) = array_map('trim', explode(':', trim($tw_info)) + [null, $tw_info]);

        // 如果只传了 3 位数字，自动补全成 MOD_LIVE_00000000XXX
        if (preg_match('/^\d{3}$/', $idPart)) {
            $contentPk = sprintf("MOD_LIVE_%010d", intval($idPart));
        } else {
            $contentPk = $idPart;
        }

        $channelProgrammes[$contentPk]['channel_name'] = cleanChannelName($channelName);

        $processCount = 0;
        foreach ($need_dates as $need_date) {
            $post_data = http_build_query([
                'contentPk' => $contentPk,
                'date'      => $need_date
            ]);

            $context = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                    'content' => $post_data
                ]
            ]);

            $json_data = file_get_contents('https://mod.cht.com.tw/channel/epg.do', false, $context);
            if (!$json_data) continue;

            $epg_list = json_decode($json_data, true);
            if (!is_array($epg_list)) continue;

            foreach ($epg_list as $epg) {
                $channelProgrammes[$contentPk]['diyp_data'][$need_date][] = [
                    'start'  => $epg['startTimeVal'] ?? '',
                    'end'    => $epg['endTimeVal'] ?? '',
                    'title'  => trim($epg['programName'] ?? ''),
                    'desc'   => '',
                    'status' => $epg['timeClass'] ?? ''
                ];
            }
            $processCount += count($epg_list);
        }
        $channelProgrammes[$contentPk]['process_count'] = $processCount;
    }

    return $channelProgrammes;
}

?>
