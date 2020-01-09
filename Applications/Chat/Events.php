<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
declare(ticks=1);

/**
 * 聊天主逻辑
 * 主要是处理 onMessage onClose 
 */

use \GatewayWorker\Lib\Gateway;
use \GatewayWorker\Lib\Db;

class Events
{
   /**
    * 有消息时
    * @param int $client_id
    * @param mixed $message
    */
   public static function onMessage($client_id, $message)
   {
        // debug
        $chat = Db::instance('chat');
        echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id session:".json_encode($_SESSION)." onMessage:".$message."\n";
        // 客户端传递的是json数据
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return;
        }
        // 根据类型执行不同的业务
        switch($message_data['type'])
        {
            // 客户端回应服务端的心跳
            case 'pong':
                return;
            // 客户端登录 message格式: {type:login, name:xx, room_id:1} ，添加到客户端，广播给所有客户端xx进入聊天室
            case 'wxGame':
                // 把房间号昵称放到session中
                $room_id = $message_data['room_id'];
                $client_name = htmlspecialchars($message_data['client_name']);
                $_SESSION['room_id'] = $room_id;
                $_SESSION['client_name'] = $client_name;
              
                // 获取房间内所有用户列表 
                $clients_list = Gateway::getClientSessionsByGroup($room_id);
                foreach($clients_list as $tmp_client_id=>$item)
                {
                    $clients_list[$tmp_client_id] = $item['client_name'];
                }
                $clients_list[$client_id] = $client_name;
                
                // 转播给当前房间的所有客户端，xx进入聊天室 message {type:login, client_id:xx, name:xx} 
                $new_message = array('type'=>$message_data['type'], 'client_id'=>$client_id, 'client_name'=>htmlspecialchars($client_name), 'time'=>date('Y-m-d H:i:s'));
                Gateway::sendToGroup($room_id, json_encode($new_message));
                Gateway::joinGroup($client_id, $room_id);
               
                // 给当前用户发送用户列表 
                $new_message['client_list'] = $clients_list;
                Gateway::sendToCurrentClient(json_encode($new_message));
                return;
             // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'gamersay':
                // 非法请求
                if(!isset($_SESSION['room_id']))
                {
                    throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
                }
                $room_id = $_SESSION['room_id'];
                $client_name = $_SESSION['client_name'];
                
                $new_message = array(
                    'type'=>'say', 
                    'from_client_id'=>$client_id,
                    'from_client_name' =>$client_name,
                    'to_client_id'=>'all',
                    'content'=>'4',
                    'time'=>date('Y-m-d H:i:s'),
                );
                return Gateway::sendToGroup($room_id ,json_encode($new_message));

            case 'login':
                $client_name = htmlspecialchars($message_data['client_name']);
                $_SESSION['client_name'] = $client_name;
                $_SESSION['user_id'] = $message_data['user_id'];
                Gateway::bindUid($client_id, $message_data['user_id']);
                //使用room_id区分群聊和私聊
                if(isset($message_data['room_id']) || (isset($message_data['room_id']) && $message_data['room_id']== '0'))
                {
                    //直接进入群聊页面
                    if($message_data['room_id'] > 0) {
                        $room_id = $message_data['room_id'];
                        $_SESSION['room_id'] = $room_id;
                        Gateway::joinGroup($client_id, $room_id);
                        //获取群聊天记录
                        $chat_group_log = $chat->query('select * from chat_group_log where group_id = '.$room_id.' order by created_at asc');
                        if($chat_group_log) {
                            //更新群消息读取信息
                            $chat_group_log_user = $chat->query('select id from chat_group_log_user where group_id = '.$room_id. ' and user_id = '.$message_data['user_id']);
                            $max_chat_log_id = $chat->query('select max(id) from chat_group_log where group_id = '.$room_id);
                             //如果存在群消息并且没有读取记录，存储一条读取至最后一条消息的记录
                            if($chat_group_log) {
                                if(!$chat_group_log_user) {
                                    $insert_chat_group_log = $chat
                                        ->insert('chat_group_log_user')
                                        ->cols(array(
                                            'user_id' => $message_data['user_id'], 
                                            'group_id' => $room_id, 
                                            'update_at' => time(), 
                                            'group_log_id' => $max_chat_log_id[0]['max(id)']
                                            ))
                                    ->query();
                                //更新当前读取记录
                                } else {
                                    $chat->update('chat_group_log_user')->cols(array('group_log_id'=>$max_chat_log_id[0]['max(id)']))->where('id ='.$chat_group_log_user[0]['id'])->query();

                                }
                            }
                        }
                        //群聊记录
                        if($chat_group_log) {
                            foreach ($chat_group_log as $logKey => $logValue) {
                                $send_headimg = $chat->query('select headimg from chat_user where user_id = '.$logValue['send_id']);
                                $log_message = array(
                                    'type'=>'saytosomeone',
                                    'from_client_id'=>$logValue['send_id'],
                                    'from_client_name' =>$client_name,
                                    'user_id'=>$message_data['user_id'],
                                    'to_client_id'=>$client_id,
                                    'content'=>$logValue['content'],
                                    'group_id'=> $room_id,
                                    'time'=>date('m-d H:i'),
                                    'send_headimg' => $send_headimg?$send_headimg[0]['headimg']:'',
                                    'send_username' => $logValue['send_username'],
                                    //发送人信息
                                    // 'headimg_id'=> $info['user_id']
                                );
                                //初始化群聊记录
                                Gateway::sendToUid($message_data['user_id'], json_encode($log_message));
                            }
                        }
                        //获取群信息
                        $room_noti = $chat->query('select * from chat_group where id = '.$room_id);
                        //群通知
                        $noti_message = array(
                            'type'=>'saytosomeone',
                            'from_client_id'=>$room_noti[0]['created_by'],
                            'from_client_name' =>$client_name,
                            'user_id'=>$message_data['user_id'],
                            'to_client_id'=>$client_id,
                            'content'=>$room_noti[0]['noti'],
                            'time'=>date('m-d H:i'),
                            'group_id' => $room_id,
                            'send_headimg'=>$room_noti[0]['noti_headimg'],
                            'send_username'=>$room_noti[0]['noti_username']
                            //发送人信息
                            // 'headimg_id'=> $info['user_id']
                        );
                        $chat_group_user = $chat->select('*')
                                                ->from('chat_group_user')
                                                ->where('group_id= :group_id')
                                                ->bindValues(array('group_id'=>$room_id))
                                                ->query();
                        $is_noti = $chat->query('select id,is_noti from chat_group_user where user_id = '.$message_data['user_id'].' and group_id = '.$room_id);
                        //如果用户没接收过群通知，发送群通知给登陆的用户
                        if($is_noti[0]['is_noti'] != 1) {
                            Gateway::sendToUid($message_data['user_id'], json_encode($noti_message));
                            //更新用户接收通知状态
                            $chat->update('chat_group_user')->cols(array('is_noti'=>1))->where('id ='.$is_noti[0]['id'])->query();
                        }
                    //获取群聊列表
                    } else {
                        //初始化群聊列表
                        //获取所在群
                        $chat_group_user = $chat->query('select group_id from chat_group_user where user_id = '.$message_data['user_id']);
                        $send_time = [];
                        //按照最后一条群消息时间重新排序
                        foreach ($chat_group_user as $key => $groupValue) {
                            //把用户加入多个群组，接收列表所有群组的消息
                            Gateway::joinGroup($client_id, $groupValue['group_id']);
                            //获取群信息
                            $chat_group = $chat->query('select name from chat_group where id = '.$groupValue['group_id']);
                            $chat_group_user[$key]['name'] = $chat_group[0]['name'];
                            $chat_group_user[$key]['not_read'] = 0;
                            $chat_group_user[$key]['content'] = '';
                            $chat_group_user[$key]['time'] = '';
                            //获取群内最后一条消息
                            $chat_group_log = $chat->query('select id,send_id,content,created_at from chat_group_log where group_id = '.$groupValue['group_id'].' order by created_at desc limit 1');
                            if($chat_group_log) {
                                $send_time[] = $chat_group_log[0]['created_at'];
                                $chat_group_user[$key]['content'] = $chat_group_log[0]['content'];
                                $chat_group_user[$key]['username'] = $chat->query('select * from chat_user where user_id = '.$chat_group_log[0]['send_id']);
                                $chat_group_user[$key]['time'] = date('m-d H:i', $chat_group_log[0]['created_at']);
                                //如果存在未读的群消息，给出特殊标记
                                $chat_group_log_user = $chat->query('select group_log_id from chat_group_log_user where group_id = '.$groupValue['group_id']. ' and user_id = '.$message_data['user_id']);
                                //读取过，对比读取的最后一条和群内最新消息
                                if($chat_group_log_user) {
                                    $chat_group_user[$key]['not_read'] = $chat_group_log[0]['id'] > $chat_group_log_user[0]['group_log_id']?1:0;
                                } else {
                                    $chat_group_user[$key]['not_read'] = 1;
                                }
                            } else {
                                $send_time[] = 0;
                            }
                        }
                        array_multisort($send_time, SORT_DESC, $chat_group_user);
                        foreach ($chat_group_user as $key => $groupValue) {
                            $info_group_message = $new_message = array(
                                'type'=>'info_group_start',
                                'content'=>$groupValue['content'],
                                'time'=>$groupValue['time'],
                                'group_id'=>$groupValue['group_id'],
                                // 'send_username'=> $username[0]['username'],
                                'send_headimg' => '',
                                'not_read' => $groupValue['not_read'],
                                'count_not_read' => '',
                                'name' => $groupValue['name']
                            );
                            Gateway::sendToUid($message_data['user_id'], json_encode($info_group_message));
                        }
                    }
                } else {
                    //获取当前用户所有通信记录，生成聊天列表
                    //接收的消息
                    if($message_data['to_user_id'] == 0) {
                        //获取用户未读群消息数
                        //查询用户所在群
                        $groups = $chat->query('select group_id from chat_group_user where user_id = '.$message_data['user_id']);
                        $group_count_not_read = 0;
                        foreach ($groups as $key => $value) {
                            //查询有没有读取过当前群的记录
                            $chat_group_log_user = $chat->query('select group_log_id from chat_group_log_user where user_id = '.$message_data['user_id'].' and group_id = '.$value['group_id']);
                            //如果没接收过群消息，显示总数
                            if(!$chat_group_log_user) {
                                $group_not_read = $chat->query('select count(id) from chat_group_log where group_id ='.$value['group_id'])[0]['count(id)'];
                            //接收过，显示最后一条接收之后的消息数
                            } else {
                                $group_not_read = $chat->query('select count(id) from chat_group_log where group_id = '.$value['group_id'].' and id > '.$chat_group_log_user[0]['group_log_id'])[0]['count(id)'];
                            }
                            $group_count_not_read += $group_not_read;
                        }
                        //接收的消息
                        $receive_info_list = $chat->query('select id,content,send_id,receive_id,id,send_time  from chat_log where receive_id = '.$message_data['user_id'].' group by send_id');
                        //发送的消息
                        $send_info_list = $chat->query('select id,content,send_id,receive_id,id,send_time from chat_log where send_id = '.$message_data['user_id'].' group by receive_id');
                        $info_list = array_merge($receive_info_list, $send_info_list);
                        $send_time = [];
                        $to_user_id_array = [];
                        //删除多余数并重新排序
                        foreach ($info_list as $key => $value) {
                            $to_user_id = $value['send_id'] == $message_data['user_id']?$value['receive_id']:$value['send_id'];
                            if(!array_key_exists($to_user_id, $to_user_id_array)){
                                //获取通信的最后一条消息的时间，用于列表排序
                                $last_send_time = $chat->query('select max(send_time) from chat_log where send_id = '.$to_user_id. ' and receive_id = '.$message_data['user_id']. ' or send_id = '.$message_data['user_id'].' and receive_id = '.$to_user_id);
                                $send_time[] = $last_send_time?$last_send_time[0]['max(send_time)']:0;
                                $to_user_id_array[$to_user_id] = 1;
                            } else {
                                unset($info_list[$key]);
                            }
                        }
                        array_multisort($send_time, SORT_DESC, $info_list);
                        foreach ($info_list as $listKey => &$listValue) {
                            if($listValue['send_id']==$message_data['user_id']) {
                                $listUserId = $listValue['receive_id'];
                                $send_id = $message_data['user_id'];
                                $receive_id = $listUserId;
                            } else {
                                $listUserId = $listValue['send_id'];
                                $send_id = $listUserId;
                                $receive_id = $message_data['user_id'];
                            }
                            //最后一条通信消息
                            $max_time_chat_log = $chat->query('select * from chat_log where send_id = '.$send_id.' and receive_id = '.$receive_id.' or send_id = '.$receive_id.' and receive_id = '.$send_id.' order by send_time desc limit 1');
                            //初始化列表未读消息数目
                            if($listValue['send_id'] != $message_data['user_id']) {
                                $count_not_read = $chat->query('select count(*) from chat_log where receive_id = '.$message_data['user_id'].' and send_id = '.$listValue['send_id'].' and status = 0')[0]['count(*)'];
                            } else {
                                $count_not_read = 0;
                            }
                            $info_username = $chat->query('select username from user where id = '.$listUserId)['0']['username'];
                            $info_headimg = $chat->query('select headimgurl from profile where user_id = '.$listUserId);
                            if($max_time_chat_log[0]['id'] == $listValue['id']) {
                                $content = $listValue['content'];
                                $send_time = date('m-d H:i',$listValue['send_time']);
                                $id = $listValue['id'];
                            } else {
                                $content = $max_time_chat_log[0]['content'];
                                $send_time = date('m-d H:i',$max_time_chat_log[0]['send_time']);
                                $id = $max_time_chat_log[0]['id'];
                            }
                            $info_message = $new_message = array(
                                'type'=>'info_start',
                                'from_client_name' =>$client_name,
                                'user_id'=>$listUserId,
                                'content'=>nl2br(htmlspecialchars($content)),
                                'time'=>$send_time,
                                //发送人信息
                                'username'=> $info_username,
                                'headimg' => $info_headimg?$info_headimg['0']['headimgurl']:'',
                                'count_not_read' => $count_not_read,
                                'id' => $id,
                                'group_count_not_read' => $group_count_not_read
                            );
                            Gateway::sendToUid($message_data['user_id'], json_encode($info_message));
                        }
                    }
                    if($message_data['to_user_id'] && $message_data['to_user_id'] > 0) {
                        $to_user_id = $message_data['to_user_id'];
                        // Gateway::sendToCurrentClient(json_encode($new_message));
                        //检测用户id下有没有与当前用户id通信的消息
                        $news = $chat->query('select * from chat_log where receive_id = '.$message_data['user_id'].' and send_id = '.$to_user_id.' or receive_id = '.$to_user_id.' and send_id = '.$message_data['user_id'].' order by send_time ASC');
                        //把接收用户为自己并且发送用户为当前打开窗口的未读消息更新成已读
                        $no_receive_news = $chat->query('select id from chat_log where receive_id = '.$message_data['user_id'].' and send_id = '.$to_user_id. ' and status = 0');
                        if($no_receive_news) {
                            foreach ($no_receive_news as $noKey => $noValue) {
                                $chat->update('chat_log')->cols(array('status'=>1))->where('id ='.$noValue['id'])->query();
                            }
                        }
                        //直接打开带参数的窗口，查询未读消息数量（因为没有初始化列表）
                        $count_not_read = $chat->query('select count(id) from chat_log where receive_id = '.$message_data['user_id']. ' and status = 0 and id <> '.$message_data['to_user_id']);
                        $count_not_read = $count_not_read?$count_not_read[0]['count(id)']:0;
                        //如果存在，发送这些消息
                        $new_message = array();
                        $new_message['count_not_read'] = $count_not_read;
                        $new_message['to_one_content'] = '';
                        if($news) {
                            foreach ($news as $key => $value) {
                                // Gateway::sendToUid($value['receive_id'], $new_message);
                                $new_message['to_one_content'][$key] = nl2br(htmlspecialchars($value['content']));
                                $new_message['headimg_id'][$key]= $value['send_id'];
                                $new_message['time'][$key] = date('m-d H:i', $value['send_time']);
                                $new_message['id'][$key] = $value['id'];
                            }
                        }
                        return Gateway::sendToCurrentClient(json_encode($new_message));
                    }
                }
                return;

            // 客户端发言 message: {type:say, to_client_id:xx, content:xx}
            case 'saytosomeone':
                // 非法请求
                $client_name = $_SESSION['client_name'];
                $user_id = $message_data['user_id'];
                // 单人聊天(私聊)
                if($message_data['to_client_id'] != 'all' && !isset($_SESSION['room_id']))
                {
                    // 获取发送用户的信息，通过client_id
                    $info = Gateway::getSession($client_id);
                    $new_message = array(
                        'type'=>'saytosomeone',
                        'from_client_id'=>$client_id,
                        'from_client_name' =>$client_name,
                        'user_id'=>$user_id,
                        'to_client_id'=>$message_data['to_client_id'],
                        'content'=>nl2br(htmlspecialchars($message_data['content'])),
                        'time'=>date('m-d H:i'),
                        //发送人信息
                        'headimg_id'=> $info['user_id']
                    );
                    //如果当前用户在线
                    $new_message['content'] = nl2br(htmlspecialchars($message_data['content']));
                    if(Gateway::isUidOnline($user_id)){
                        //标记消息状态 1 已读 0 未读
                        $log_status = 1;
                    } else {
                        $log_status = 0;
                    }
                    $push_send_time = time();
                    // 存储私聊信息
                    $insert_log = $chat
                        ->insert('chat_log')
                        ->cols(array(
                            'send_id' => $info['user_id'], 
                            'receive_id' => $message_data['to_client_id'], 
                            'send_time' => $push_send_time,
                            'content' => $message_data['content'],
                            'status' => $log_status,
                        ))
                    ->query();
                    $new_message['id'] = $insert_log;
                    Gateway::sendToUid($new_message['user_id'], json_encode($new_message));
                    if(!Gateway::isUidOnline($user_id)){
                        //如果接收用户不在线，插入push消息
                        self::newsPush($info['user_id'], $message_data['to_client_id'], $insert_log, $push_send_time, 1, $client_name);
                    }
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                    //单人聊天（群聊）
                } else {

                }
            case 'say':
                // 非法请求
                if(!isset($_SESSION['room_id']))
                {
                    throw new \Exception("\$_SESSION['room_id'] not set. client_ip:{$_SERVER['REMOTE_ADDR']}");
                }
                $room_id = $_SESSION['room_id'];
                $client_name = $_SESSION['client_name'];
                
                // 私聊
                if($message_data['to_client_id'] != 'all')
                {
                    $new_message = array(
                        'type'=>'say',
                        'from_client_id'=>$client_id, 
                        'from_client_name' =>$client_name,
                        'to_client_id'=>$message_data['to_client_id'],
                        'content'=>nl2br(htmlspecialchars($message_data['content'])),
                        'time'=>date('m-d H:i'),
                    );
                    Gateway::sendToClient($message_data['to_client_id'], json_encode($new_message));
                    $new_message['content'] = nl2br(htmlspecialchars($message_data['content']));
                    return Gateway::sendToCurrentClient(json_encode($new_message));
                } else {
                    //存储群聊聊天记录
                    $info = Gateway::getSession($client_id);
                    $user = $chat->query('select username from user where id = '.$info['user_id']);
                    $insert_group_log = $chat
                            ->insert('chat_group_log')
                            ->cols(array(
                                'send_id' => $info['user_id'], 
                                'created_at' => time(), 
                                'content' => $message_data['content'],
                                'group_id' => $message_data['room_id'],
                                'send_username' => $user[0]['username']
                            ))
                        ->query();
                    //更新自己的阅读群消息记录
                    $is_read = $chat->update('chat_group_log_user')
                        ->cols(array('group_log_id'=>$insert_group_log))
                        ->where('user_id ='.$message_data['user_id'].' and group_id = '.$room_id)
                        ->query();
                    if(!$is_read){
                        $insert_chat_group_log = $chat
                            ->insert('chat_group_log_user')
                            ->cols(array(
                                'user_id' => $message_data['user_id'], 
                                'group_id' => $room_id, 
                                'update_at' => time(), 
                                'group_log_id' => $insert_group_log
                                ))
                            ->query();
                    }

                    $headimg = $chat->query('select headimg from chat_user where user_id = '.$info['user_id']);
                    $new_message = array(
                        'type'=>'say', 
                        'from_client_id'=> $info['user_id'],
                        'from_client_name' => $user[0]['username'],
                        'to_client_id'=>'all',
                        'group_id' => $room_id,
                        'content'=>nl2br(htmlspecialchars($message_data['content'])),
                        'time'=>date('m-d H:i'),
                        'send_username' => $user[0]['username'],
                        'send_headimg' => $headimg?$headimg[0]['headimg']:''
                    );
                    return Gateway::sendToGroup($room_id ,json_encode($new_message));
                }
        }
   }
   /**
     * 组局审核通知
     * [GroupCancel description]
     */
    public static function newsPush($send_id, $receive_id, $from_id, $push_send_time, $type, $from_name)
    {
        $chat = Db::instance('chat');
        $openid = $chat->query('select client_id from social_account where user_id = '.$receive_id)[0]['client_id'];
        // 获取报名成功的push模板
        $statusText = $type == 1?'好友消息提醒':'群聊消息提醒';
        $push_type = $type == 1?67:68;
        $from_type = $type == 1?'friend_news':'group_news';
        $data = self::insertPush($send_id, $openid, $push_send_time, $type, $from_name);
        $insert_news_push = $chat
                    ->insert('wechat_push')
                    ->cols(array(
                        'answer_id' => 0,
                        'status' => 10,
                        'type' => $push_type,
                        'from_type' => $from_type,
                        'from_id' => $from_id,
                        'user_id' => $receive_id,
                        'data' => json_encode($data),
                        'note' => $statusText,
                        'openid' => $openid,
                        'url' => $data['url'],
                        'template_id' => $data['template_id'],
                        'created_at' => time(),
                        'join_queue_at' => 0
                        ))
                    ->query();
    }

   public static function insertPush($send_id, $openid, $push_send_time, $type, $from_name)
   {
        //获取模板消息id
        //本地
        // $template_id = 'bUaM7UKEHOtvxLbepK0PQz1_LOQ-wWbAMM-6F1yoDDA';
        $template_id = 'pfZ5LzFslpe1Lt7D95eoYtY6xJ6XT95_ZDEs1SzhXeM';
        $first_value = "你收到一条来自好友的呼叫\n戳这里>查看呼叫消息内容并回复TA\n";
        $send_time = date('m月d日 H:i', $push_send_time);
        $push_type = $type == 1?'好友消息':'组局群聊';
        $url = 'https://m.someet.cc/map-friend/chat?to_user_id='.$send_id;
        $remark_value = "\n戳上面>快去看看TA说了什么吧～";
        $data = [
            "touser" => "{$openid}",
            "template_id" => $template_id,
            "url" => $url,
            "topcolor" => "#FF0000",
            "data" => [
                "first" => [
                    "value" => $first_value,
                    "color" => "#67a58d"
                ],
                "keyword1" => [
                    "value" => "{$send_time}",
                    "color" => "#000000"
                ],
                "keyword2" => [
                    "value" => "{$push_type}",
                    "color" =>"#000000"
                ],
                "keyword3" => [
                    "value" => "{$from_name}",
                    "color" =>"#000000"
                ],
                "remark" => [
                    "value" => $remark_value,
                    "color" => "#67a58d"
                ],
            ]
        ];
        return $data;
    }
   /**
    * 当客户端断开连接时
    * @param integer $client_id 客户端id
    */
   public static function onClose($client_id)
   {
       // debug
       echo "client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} gateway:{$_SERVER['GATEWAY_ADDR']}:{$_SERVER['GATEWAY_PORT']}  client_id:$client_id onClose:''\n";
   }
  
}

