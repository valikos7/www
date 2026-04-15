<?php
// cron.php
define('CRON_RUN', true);

if (php_sapi_name() !== 'cli') {
    $secret=$_GET['secret']??''; $valid='YOUR_SECRET_KEY_HERE';
    if ($secret!==$valid) { http_response_code(403); die("Access denied"); }
}

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/ResourceManager.php';
require_once __DIR__ . '/core/BattleEngine.php';
require_once __DIR__ . '/core/GameHelper.php';
require_once __DIR__ . '/controllers/BattleController.php';
require_once __DIR__ . '/controllers/SettlerController.php';
require_once __DIR__ . '/controllers/MarketController.php';
require_once __DIR__ . '/controllers/HeroController.php';
require_once __DIR__ . '/controllers/EventController.php';
require_once __DIR__ . '/controllers/QuestController.php';
require_once __DIR__ . '/controllers/TechController.php';
require_once __DIR__ . '/controllers/NoblemenController.php';
require_once __DIR__ . '/controllers/RankController.php';
require_once __DIR__ . '/controllers/BlackMarketController.php';
require_once __DIR__ . '/controllers/AchievementController.php';
require_once __DIR__ . '/controllers/SeasonController.php';
require_once __DIR__ . '/controllers/AllianceWarController.php';
require_once __DIR__ . '/controllers/SpyNetworkController.php';

$log_dir=$__DIR__.'/logs/'; $log_file=$log_dir.'cron.log';
if (!is_dir($log_dir)) mkdir($log_dir,0755,true);
if (!file_exists($log_dir.'.htaccess')) file_put_contents($log_dir.'.htaccess',"deny from all\n");

function cron_log($msg,$level='INFO') {
    global $log_file;
    $line="[".date('Y-m-d H:i:s')."] [{$level}] {$msg}".PHP_EOL;
    echo $line; file_put_contents($log_file,$line,FILE_APPEND|LOCK_EX);
}

if (file_exists($log_file)&&filesize($log_file)>5*1024*1024){file_put_contents($log_file,'');cron_log("Лог очищен");}

$cron_start=microtime(true);
cron_log("========== CRON START ==========");

try {
    $database=Database::getInstance(); $db=$database->getPdo();
    BattleEngine::init($db); cron_log("БД подключена");
} catch (Exception $e) { cron_log("ОШИБКА БД: ".$e->getMessage(),'ERROR'); exit(1); }

// 1. РЕСУРСЫ
cron_log("--- [1] Ресурсы ---");
try {
    $vl=$db->query("SELECT id FROM villages WHERE userid>0")->fetchAll();
    $updated=0; $errors=0; $res=new ResourceManager($db);
    foreach ($vl as $v) { try{$res->updateResources($v['id']);$updated++;}catch(Exception $e){$errors++;} }
    cron_log("Деревень:{$updated}".($errors>0?",ошибок:{$errors}":''));
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 2. ВОЙСКА
cron_log("--- [2] Войска ---");
try {
    $cnt=$db->query("SELECT COUNT(*) as c FROM troop_movements WHERE status='moving' AND arrival_time<=".time())->fetch()['c']??0;
    if($cnt>0){(new BattleController($db))->processArrivals();cron_log("Прибытий:{$cnt}");}
    else cron_log("Прибытий нет");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 3. ПОСЕЛЕНЦЫ
cron_log("--- [3] Поселенцы ---");
try {
    $cnt=$db->query("SELECT COUNT(*) as c FROM settlers WHERE status='moving' AND arrival_time<=".time())->fetch()['c']??0;
    if($cnt>0){(new SettlerController($db))->processArrivals();cron_log("Поселенцев:{$cnt}");}
    else cron_log("Поселенцев нет");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 4. ТОРГОВЛЯ
cron_log("--- [4] Торговля ---");
try {
    $cnt=$db->query("SELECT COUNT(*) as c FROM internal_trades WHERE status='moving' AND arrival_time<=".time())->fetch()['c']??0;
    if($cnt>0){(new MarketController($db))->processArrivals();cron_log("Путей:{$cnt}");}
    else cron_log("Торговых путей нет");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 5. СТРОИТЕЛЬСТВО
cron_log("--- [5] Строительство ---");
try {
    $done_bq=$db->query("SELECT bq.*,v.userid FROM build_queue bq JOIN villages v ON bq.village_id=v.id WHERE bq.status='building' AND bq.end_time<=".time())->fetchAll();
    $built=0;
    foreach ($done_bq as $b) {
        $db->prepare("UPDATE villages SET `{$b['building']}`=? WHERE id=?")->execute([$b['level'],$b['village_id']]);
        $pts_map=['main'=>10,'wood_level'=>4,'stone_level'=>4,'iron_level'=>4,'farm'=>5,'storage'=>5,'barracks'=>6,'stable'=>8,'smith'=>6,'garage'=>7,'wall'=>6,'hide'=>3];
        $pts=($pts_map[$b['building']]??4)*$b['level'];
        $db->prepare("UPDATE villages SET points=points+? WHERE id=?")->execute([$pts,$b['village_id']]);
        try {
            $db->prepare("INSERT INTO player_stats (user_id,buildings_built) VALUES (?,1) ON DUPLICATE KEY UPDATE buildings_built=buildings_built+1")->execute([$b['userid']]);
            QuestController::trigger($db,$b['userid'],'build_1',1); QuestController::trigger($db,$b['userid'],'build_3',1);
            QuestController::trigger($db,$b['userid'],'build_10',1); QuestController::trigger($db,$b['userid'],'first_build',1);
            AchievementController::check($db,$b['userid']);
        } catch (Exception $e) {}
        $db->prepare("UPDATE build_queue SET status='done' WHERE id=?")->execute([$b['id']]);
        $db->prepare("UPDATE build_queue SET position=position-1 WHERE village_id=? AND status!='done' AND position>?")->execute([$b['village_id'],$b['position']]);
        $built++;
    }
    $pending_bq=$db->query("SELECT * FROM build_queue WHERE status='pending' ORDER BY village_id,position ASC")->fetchAll();
    $proc_v=[];$started=0;
    foreach ($pending_bq as $pb) {
        if(isset($proc_v[$pb['village_id']])) continue;
        $chk=$db->prepare("SELECT id FROM build_queue WHERE village_id=? AND status='building'"); $chk->execute([$pb['village_id']]);
        if(!$chk->fetch()) { $dur=$pb['end_time']-$pb['start_time']; $db->prepare("UPDATE build_queue SET status='building',start_time=?,end_time=? WHERE id=?")->execute([time(),time()+$dur,$pb['id']]); $proc_v[$pb['village_id']]=true;$started++; }
    }
    cron_log("Завершено:{$built},запущено:{$started}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 6. ОЧКИ
cron_log("--- [6] Очки ---");
try {
    $db->query("UPDATE users u SET u.points=(SELECT COALESCE(SUM(v.points),0) FROM villages v WHERE v.userid=u.id)");
    $db->query("UPDATE users u SET u.villages=(SELECT COUNT(*) FROM villages v WHERE v.userid=u.id)");
    cron_log("Пересчитано");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 7. АЛЬЯНСЫ
cron_log("--- [7] Альянсы ---");
try {
    $als=$db->query("SELECT id FROM alliances")->fetchAll();
    foreach ($als as $a) {
        $r=$db->prepare("SELECT COALESCE(SUM(u.points),0) as t,COUNT(*) as m FROM alliance_members am JOIN users u ON am.user_id=u.id WHERE am.alliance_id=?");
        $r->execute([$a['id']]); $row=$r->fetch();
        $db->prepare("UPDATE alliances SET points=?,members_count=? WHERE id=?")->execute([$row['t']??0,$row['m']??0,$a['id']]);
    }
    cron_log("Альянсов:".count($als));
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 8. ВАРВАРЫ
cron_log("--- [8] Варвары ---");
try {
    $barb=$db->query("SELECT COUNT(*) as c FROM villages WHERE userid=-1")->fetch()['c']??0;
    if($barb<80){
        $need=min(80-$barb,20);$created=0;
        $names=['Заброшенный хутор','Дикий лагерь','Разбойничий стан','Варварский посёлок','Лесной лагерь','Горный форт','Тёмное убежище','Волчье логово','Каменный лагерь','Речной пост'];
        for($i=0;$i<$need;$i++){
            $found=false; for($att=0;$att<10;$att++){$x=rand(-200,200);$y=rand(-200,200);$chk=$db->prepare("SELECT id FROM villages WHERE x=? AND y=?");$chk->execute([$x,$y]);if(!$chk->fetch()){$found=true;break;}}
            if(!$found) continue;
            $name=$names[array_rand($names)];$continent=floor(($y+500)/100)*10+floor(($x+500)/100);
            $db->prepare("INSERT INTO villages (userid,name,x,y,continent,main,wood_level,stone_level,iron_level,farm,storage,wall,r_wood,r_stone,r_iron,last_prod_aktu,points) VALUES (-1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$x,$y,$continent,rand(1,5),rand(1,5),rand(1,5),rand(1,5),rand(1,3),rand(1,3),rand(0,3),rand(300,5000),rand(300,5000),rand(300,5000),time(),rand(20,300)]);
            $created++;
        }
        cron_log("Было:{$barb},создано:{$created}");
    } else cron_log("Достаточно:{$barb}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 9. ОЧИСТКА
cron_log("--- [9] Очистка ---");
try {
    $old3=time()-3*86400;$old7=time()-7*86400;$old14=time()-14*86400;$old30=time()-30*86400;
    $s1=$db->prepare("DELETE FROM troop_movements WHERE status='completed' AND arrival_time<?");$s1->execute([$old7]);
    $s2=$db->prepare("DELETE FROM activity_log WHERE time<?");$s2->execute([$old30]);
    $s3=$db->prepare("DELETE FROM reports WHERE is_read=1 AND time<?");$s3->execute([$old14]);
    $s4=$db->prepare("DELETE FROM messages WHERE deleted_by_sender=1 AND deleted_by_receiver=1");$s4->execute();
    $s5=$db->prepare("DELETE FROM settlers WHERE status IN('arrived','cancelled') AND arrival_time<?");$s5->execute([$old7]);
    $s6=$db->prepare("DELETE FROM internal_trades WHERE status='arrived' AND arrival_time<?");$s6->execute([$old3]);
    $s7=$db->prepare("DELETE FROM market_offers WHERE status IN('completed','cancelled') AND created_at<?");$s7->execute([$old7]);
    $s8=$db->prepare("DELETE FROM build_queue WHERE status='done'");$s8->execute();
    cron_log("движений:{$s1->rowCount()} логов:{$s2->rowCount()} отчётов:{$s3->rowCount()} build_queue:{$s8->rowCount()}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 10. ИСТОРИЯ
cron_log("--- [10] История ---");
try {
    $last=$db->query("SELECT MAX(recorded_at) as l FROM points_history")->fetch()['l']??0;
    if(time()-$last>=3600){
        $users=$db->query("SELECT id,points,villages FROM users WHERE points>0")->fetchAll(); $recorded=0;
        foreach($users as $u){$db->prepare("INSERT INTO points_history (user_id,points,villages,recorded_at) VALUES (?,?,?,?)")->execute([$u['id'],$u['points'],$u['villages'],time()]);$recorded++;}
        try{$db->query("DELETE ph1 FROM points_history ph1 INNER JOIN (SELECT id FROM (SELECT id,ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY recorded_at DESC) as rn FROM points_history) t WHERE rn>30) ph2 ON ph1.id=ph2.id");}catch(Exception $e){}
        cron_log("Записей:{$recorded}");
    } else cron_log("Пропуск (рано)");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 11. ГЕРОИ
cron_log("--- [11] Герои ---");
try {
    $hc=new HeroController($db); $stmt=$db->query("SELECT user_id FROM heroes WHERE status='regenerating' AND revive_time<=".time()); $revived=0;
    foreach($stmt->fetchAll() as $h){$hc->reviveHero($h['user_id']);$db->prepare("INSERT INTO reports (userid,type,title,content,time,is_read) VALUES (?,'system',?,?,?,0)")->execute([$h['user_id'],"🦸 Ваш герой возродился!","Герой восстановлен и готов к бою!",time()]);$revived++;}
    cron_log("Возрождено:{$revived}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 12. СОБЫТИЯ
cron_log("--- [12] События ---");
try { $ec=new EventController($db); $ec->cronCheck(); $active=$ec->getActiveEvent(); cron_log($active?"Активное:{$active['title']}":"Нет активных событий"); }
catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 13. КВЕСТЫ
cron_log("--- [13] Квесты ---");
try {
    $s1=$db->prepare("DELETE qp FROM quest_progress qp JOIN quests q ON qp.quest_id=q.id WHERE q.type='daily' AND qp.date<DATE_SUB(CURDATE(),INTERVAL 2 DAY)");$s1->execute();
    $s2=$db->prepare("DELETE qp FROM quest_progress qp JOIN quests q ON qp.quest_id=q.id WHERE q.type='weekly' AND qp.date<DATE_SUB(CURDATE(),INTERVAL 14 DAY)");$s2->execute();
    cron_log("Очищено ежедн.:{$s1->rowCount()},еженед.:{$s2->rowCount()}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 14. ТЕХНОЛОГИИ
cron_log("--- [14] Технологии ---");
try {
    $stmt=$db->query("SELECT DISTINCT user_id FROM player_technologies WHERE researching=1 AND end_time<=".time());
    $tc=new TechController($db);$completed=0;
    foreach($stmt->fetchAll() as $u){$tc->processCompleted($u['user_id']);$completed++;}
    cron_log("Завершено для {$completed} игроков");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 15. ЛОЯЛЬНОСТЬ
cron_log("--- [15] Лояльность ---");
try {
    (new NoblemenController($db))->restoreLoyalty();
    $cnt=$db->query("SELECT COUNT(*) as c FROM villages WHERE loyalty<100 AND userid>0")->fetch()['c']??0;
    cron_log("Деревень с низкой лояльностью:{$cnt}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 16. РАНГИ И НАЁМНИКИ
cron_log("--- [16] Ранги и наёмники ---");
try {
    $stmt=$db->query("SELECT id,points FROM users WHERE last_activity>=".( time()-7*86400));
    $rc=new RankController($db);$ranked=0;
    foreach($stmt->fetchAll() as $u){$rc->updateRank($u['id'],$u['points']);$ranked++;}
    cron_log("Рангов:{$ranked}");
    (new BlackMarketController($db))->processExpiredMercenaries();
    $cnt=$db->query("SELECT COUNT(*) as c FROM mercenaries WHERE expires_at>".time())->fetch()['c']??0;
    cron_log("Активных наёмников:{$cnt}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 17. ДОСТИЖЕНИЯ И СЕЗОН
cron_log("--- [17] Достижения и сезон ---");
try {
    $stmt=$db->query("SELECT id FROM users WHERE last_activity>=".( time()-86400));
    $ac=new AchievementController($db);$checked=0;
    foreach($stmt->fetchAll() as $u){$ac->checkAndGrant($u['id']);$checked++;}
    cron_log("Проверено достижений для {$checked} игроков");
    $sc=new SeasonController($db); $sc->endSeasonIfNeeded();
    $season=$sc->getActiveSeason();
    cron_log($season?"Сезон:{$season['name']} до ".date('d.m.Y',$season['ends_at']):"Нет активного сезона");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// 18. ВОЙНЫ И ШПИОНЫ
cron_log("--- [18] Войны и шпионы ---");
try {
    $wc=new AllianceWarController($db); $wc->endExpiredWars();
    $cnt=$db->query("SELECT COUNT(*) as c FROM alliance_wars WHERE status='active'")->fetch()['c']??0;
    cron_log("Активных войн:{$cnt}");
    $snc=new SpyNetworkController($db); $snc->dailyCheck();
    $cnt2=$db->query("SELECT COUNT(*) as c FROM spy_network WHERE status='active'")->fetch()['c']??0;
    cron_log("Активных шпионов:{$cnt2}");
} catch (Exception $e) { cron_log("Ошибка: ".$e->getMessage(),'ERROR'); }

// ИТОГ
try {
    $s1=$db->query("SELECT COUNT(*) as c FROM users")->fetch()['c']??0;
    $s2=$db->query("SELECT COUNT(*) as c FROM villages WHERE userid>0")->fetch()['c']??0;
    $s3=$db->query("SELECT COUNT(*) as c FROM users WHERE last_activity>=".(time()-300))->fetch()['c']??0;
    $s4=$db->query("SELECT COUNT(*) as c FROM troop_movements WHERE status='moving'")->fetch()['c']??0;
    $s5=$db->query("SELECT COUNT(*) as c FROM heroes WHERE status='alive'")->fetch()['c']??0;
    cron_log("Игроков:{$s1} Онлайн:{$s3} Деревень:{$s2} Походов:{$s4} Героев:{$s5}");
} catch (Exception $e) {}

cron_log("========== CRON END [".round(microtime(true)-$cron_start,3)."с] ==========");
cron_log("");