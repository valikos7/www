<?php
session_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/core/Security.php';
require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/core/ResourceManager.php';
require_once __DIR__ . '/core/BattleEngine.php';
require_once __DIR__ . '/core/NotificationManager.php';
require_once __DIR__ . '/core/GameHelper.php';

require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/MainController.php';
require_once __DIR__ . '/controllers/VillageController.php';
require_once __DIR__ . '/controllers/VillagesController.php';
require_once __DIR__ . '/controllers/MapController.php';
require_once __DIR__ . '/controllers/ProfileController.php';
require_once __DIR__ . '/controllers/ReportController.php';
require_once __DIR__ . '/controllers/BattleController.php';
require_once __DIR__ . '/controllers/AllianceController.php';
require_once __DIR__ . '/controllers/AllianceChatController.php';
require_once __DIR__ . '/controllers/DiplomacyController.php';
require_once __DIR__ . '/controllers/MessageController.php';
require_once __DIR__ . '/controllers/RankingController.php';
require_once __DIR__ . '/controllers/PlayerController.php';
require_once __DIR__ . '/controllers/SettlerController.php';
require_once __DIR__ . '/controllers/SupportController.php';
require_once __DIR__ . '/controllers/MarketController.php';
require_once __DIR__ . '/controllers/StatsController.php';
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
require_once __DIR__ . '/controllers/AdminController.php';

$database = Database::getInstance();
$db       = $database->getPdo();

BattleEngine::init($db);
Security::generateCsrfToken();

// Проверка бана
if (isset($_SESSION['user_id'])) {
    try {
        $stmt=$db->prepare("SELECT is_banned FROM users WHERE id=?");
        $stmt->execute([$_SESSION['user_id']]);
        $u=$stmt->fetch();
        if ($u&&!empty($u['is_banned'])) {
            session_destroy();
            die("<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Заблокирован</title>
            <style>body{font-family:Arial;background:#1a1a0e;color:#ddd;display:flex;align-items:center;
            justify-content:center;height:100vh;text-align:center;margin:0;}
            h2{color:#f44;}p{color:#888;margin:10px 0;}
            a{color:#d4a843;text-decoration:none;padding:10px 20px;border:1px solid #8b6914;border-radius:6px;}
            </style></head><body><div><h2>🚫 Заблокирован</h2>
            <p>Обратитесь к администратору.</p>
            <a href='?page=home'>← На главную</a></div></body></html>");
        }
    } catch (Exception $e) {}
}

// Обновляем активность
if (isset($_SESSION['user_id'])) {
    try {
        $db->prepare("UPDATE users SET last_activity=? WHERE id=?")->execute([time(),$_SESSION['user_id']]);
    } catch (Exception $e) {}
}

// Псевдо-крон
if (rand(1,10)===1) {
    try {
        // Ресурсы
        $rm   = new ResourceManager($db);
        $stmt = $db->prepare("SELECT v.id FROM villages v JOIN users u ON v.userid=u.id
            WHERE u.last_activity>=? AND v.userid>0 LIMIT 30");
        $stmt->execute([time()-3600]);
        foreach ($stmt->fetchAll() as $v) $rm->updateResources($v['id']);

        // Строительная очередь
        $done_bq=$db->query("SELECT bq.*,v.userid FROM build_queue bq
            JOIN villages v ON bq.village_id=v.id
            WHERE bq.status='building' AND bq.end_time<=".time())->fetchAll();
        foreach ($done_bq as $b) {
            $db->prepare("UPDATE villages SET `{$b['building']}`=? WHERE id=?")->execute([$b['level'],$b['village_id']]);
            $pts_map=['main'=>10,'wood_level'=>4,'stone_level'=>4,'iron_level'=>4,'farm'=>5,'storage'=>5,
                'barracks'=>6,'stable'=>8,'smith'=>6,'garage'=>7,'wall'=>6,'hide'=>3];
            $pts=($pts_map[$b['building']]??4)*$b['level'];
            $db->prepare("UPDATE villages SET points=points+? WHERE id=?")->execute([$pts,$b['village_id']]);
            try {
                $db->prepare("INSERT INTO player_stats (user_id,buildings_built) VALUES (?,1)
                    ON DUPLICATE KEY UPDATE buildings_built=buildings_built+1")->execute([$b['userid']]);
                QuestController::trigger($db,$b['userid'],'build_1',1);
                QuestController::trigger($db,$b['userid'],'build_3',1);
                QuestController::trigger($db,$b['userid'],'build_10',1);
                QuestController::trigger($db,$b['userid'],'first_build',1);
                AchievementController::check($db,$b['userid']);
            } catch (Exception $e) {}
            $db->prepare("UPDATE build_queue SET status='done' WHERE id=?")->execute([$b['id']]);
            $db->prepare("UPDATE build_queue SET position=position-1
                WHERE village_id=? AND status!='done' AND position>?")->execute([$b['village_id'],$b['position']]);
        }

        // Pending → building
        $pending_bq=$db->query("SELECT * FROM build_queue WHERE status='pending' ORDER BY village_id,position ASC")->fetchAll();
        $proc_v=[];
        foreach ($pending_bq as $pb) {
            if (isset($proc_v[$pb['village_id']])) continue;
            $chk=$db->prepare("SELECT id FROM build_queue WHERE village_id=? AND status='building'");
            $chk->execute([$pb['village_id']]);
            if (!$chk->fetch()) {
                $dur=$pb['end_time']-$pb['start_time'];
                $db->prepare("UPDATE build_queue SET status='building',start_time=?,end_time=? WHERE id=?")
                    ->execute([time(),time()+$dur,$pb['id']]);
                $proc_v[$pb['village_id']]=true;
            }
        }

        // Очки и деревни
        $db->query("UPDATE users u SET u.points=(SELECT COALESCE(SUM(v.points),0) FROM villages v WHERE v.userid=u.id)");
        $db->query("UPDATE users u SET u.villages=(SELECT COUNT(*) FROM villages v WHERE v.userid=u.id)");

        // Альянсы
        $als=$db->query("SELECT id FROM alliances")->fetchAll();
        foreach ($als as $a) {
            $r=$db->prepare("SELECT COALESCE(SUM(u.points),0) as t,COUNT(*) as m
                FROM alliance_members am JOIN users u ON am.user_id=u.id WHERE am.alliance_id=?");
            $r->execute([$a['id']]); $row=$r->fetch();
            $db->prepare("UPDATE alliances SET points=?,members_count=? WHERE id=?")->execute([$row['t']??0,$row['m']??0,$a['id']]);
        }

        // Торговля
        (new MarketController($db))->processArrivals();

        // Герои
        $stmt=$db->query("SELECT user_id FROM heroes WHERE status='regenerating' AND revive_time<=".time());
        $hc=new HeroController($db);
        foreach ($stmt->fetchAll() as $h) $hc->reviveHero($h['user_id']);

        // События
        (new EventController($db))->cronCheck();

        // Технологии
        $stmt=$db->query("SELECT DISTINCT user_id FROM player_technologies WHERE researching=1 AND end_time<=".time());
        $tc=new TechController($db);
        foreach ($stmt->fetchAll() as $u) $tc->processCompleted($u['user_id']);

        // Лояльность
        (new NoblemenController($db))->restoreLoyalty();

        // Наёмники
        (new BlackMarketController($db))->processExpiredMercenaries();

        // Ранги
        $stmt=$db->prepare("SELECT id,points FROM users WHERE last_activity>=? LIMIT 20");
        $stmt->execute([time()-3600]);
        $rc=new RankController($db);
        foreach ($stmt->fetchAll() as $u) $rc->updateRank($u['id'],$u['points']);

        // Достижения текущего пользователя
        if (isset($_SESSION['user_id'])) {
            try { AchievementController::check($db,$_SESSION['user_id']); } catch (Exception $e) {}
        }

        // Войны альянсов
        (new AllianceWarController($db))->endExpiredWars();

    } catch (Exception $e) {}
}

// Обработка прибытий
try { (new BattleController($db))->processArrivals(); } catch (Exception $e) {}
try { (new SettlerController($db))->processArrivals(); } catch (Exception $e) {}

// Роутинг
$page   = $_GET['page']   ?? 'home';
$action = $_GET['action'] ?? '';

switch ($page) {

    case 'home':        (new MainController($db))->index();  break;

    case 'login':
        $c=new AuthController($db);
        $_SERVER['REQUEST_METHOD']==='POST'?$c->login():$c->showLoginForm();
        break;

    case 'register':
        $c=new AuthController($db);
        $_SERVER['REQUEST_METHOD']==='POST'?$c->register():$c->showRegisterForm();
        break;

    case 'logout':
        session_destroy(); header("Location: ?page=home"); exit;

    case 'profile':     (new ProfileController($db))->index(); break;
    case 'village':     (new VillageController($db))->view();  break;
    case 'villages':    (new VillagesController($db))->index(); break;
    case 'map':         (new MapController($db))->index();     break;
    case 'reports':     (new ReportController($db))->index();  break;

    case 'attack':
        $c=new BattleController($db);
        switch ($action) {
            case 'launch':  $c->launchAttack();  break;
            case 'support': $c->launchSupport(); break;
            case 'spy':     $c->launchSpy();     break;
            default:        $c->showAttackForm(); break;
        }
        break;

    case 'support':
        $c=new SupportController($db);
        $action==='recall'?$c->recall():$c->index();
        break;

    case 'market':
        $c=new MarketController($db);
        switch ($action) {
            case 'create':   $c->createOffer();   break;
            case 'accept':   $c->acceptOffer();   break;
            case 'cancel':   $c->cancelOffer();   break;
            case 'internal': $c->internalTrade(); break;
            default:         $c->index();          break;
        }
        break;

    case 'black_market':
        $c=new BlackMarketController($db);
        $action==='buy'?$c->buy():$c->index();
        break;

    case 'hero':
        $c=new HeroController($db);
        switch ($action) {
            case 'skill':      $c->addSkill();     break;
            case 'buy':        $c->buyItem();       break;
            case 'equip_item': $c->equipItem();     break;
            case 'assign':     $c->assignVillage(); break;
            default:           $c->index();         break;
        }
        break;

    case 'events':        (new EventController($db))->index();       break;

    case 'quests':
        $c=new QuestController($db);
        $action==='claim'?$c->claimReward():$c->index();
        break;

    case 'technologies':
        $c=new TechController($db);
        $action==='research'?$c->startResearch():$c->index();
        break;

    case 'nobleman':      (new NoblemenController($db))->index();    break;
    case 'ranks':         (new RankController($db))->index();        break;
    case 'achievements':  (new AchievementController($db))->index(); break;
    case 'season':        (new SeasonController($db))->index();      break;

    case 'alliances':     (new AllianceController($db))->index();    break;

    case 'alliance':
        $c=new AllianceController($db);
        switch ($action) {
            case 'create': $c->create(); break;
            case 'join':   $c->join();   break;
            case 'leave':  $c->leave();  break;
            case 'kick':   $c->kick();   break;
            default:       $c->view();   break;
        }
        break;

    case 'alliance_chat':
        $c=new AllianceChatController($db);
        switch ($action) {
            case 'get':  $c->getMessages(); break;
            case 'send': $c->sendMessage(); break;
            default:     $c->index();       break;
        }
        break;

    case 'diplomacy':
        $c=new DiplomacyController($db);
        switch ($action) {
            case 'propose': $c->propose(); break;
            case 'accept':  $c->accept();  break;
            case 'reject':  $c->reject();  break;
            case 'cancel':  $c->cancel();  break;
            default:        $c->index();   break;
        }
        break;

    case 'alliance_wars':
        $c=new AllianceWarController($db);
        $action==='declare'?$c->declareWar():$c->index();
        break;

    case 'spy_network':
        $c=new SpyNetworkController($db);
        switch ($action) {
            case 'plant':  $c->plantSpy();   break;
            case 'report': $c->getSpyReport((int)($_GET['spy_id']??0)); break;
            default:       $c->index();       break;
        }
        break;

    case 'messages':
        $c=new MessageController($db);
        $folder=$_GET['folder']??'inbox';
        switch ($action) {
            case 'compose': $c->compose(); break;
            case 'view':    $c->view();    break;
            case 'delete':  $c->delete();  break;
            default: $folder==='sent'?$c->sent():$c->inbox(); break;
        }
        break;

    case 'ranking':       (new RankingController($db))->index();    break;
    case 'player':        (new PlayerController($db))->view();      break;
    case 'stats':         (new StatsController($db))->index();      break;

    case 'settlers':
        $c=new SettlerController($db);
        $action==='send'?$c->send():$c->showForm();
        break;

    case 'api':
        header('Content-Type: application/json');
        if (($_GET['action']??'')==='search_users') {
            $q=trim($_GET['q']??'');
            if (strlen($q)>=2&&isset($_SESSION['user_id'])) {
                $stmt=$db->prepare("SELECT username FROM users WHERE username LIKE ? AND id!=? LIMIT 10");
                $stmt->execute(['%'.$q.'%',$_SESSION['user_id']]);
                echo json_encode($stmt->fetchAll());
            } else echo json_encode([]);
        } else echo json_encode(['error'=>'Unknown']);
        exit;

    case 'admin':
        $c=new AdminController($db);
        $section=$_GET['section']??'';
        switch ($action) {
            case 'player_action':       $c->playerAction();       break;
            case 'generate_barbarians': $c->generateBarbarians(); break;
            case 'broadcast':           $c->broadcastMessage();   break;
            case 'save_units':          $c->saveUnits();          break;
            case 'reset_units':         $c->resetUnits();         break;
            case 'start_event':         $c->startEventAdmin();    break;
            case 'stop_event':          $c->stopEventAdmin();     break;
            default:
                switch ($section) {
                    case 'players':       $c->players();       break;
                    case 'announcements': $c->announcements(); break;
                    case 'settings':      $c->settings();      break;
                    case 'units':         $c->units();         break;
                    case 'events':        $c->events();        break;
                    default:              $c->index();         break;
                }
                break;
        }
        break;

    default:
        http_response_code(404);
        echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>404 — ".APP_NAME."</title>
        <style>*{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:Arial;background:#1a1a0e;color:#ddd;display:flex;
        align-items:center;justify-content:center;height:100vh;text-align:center;}
        h1{color:#d4a843;font-size:80px;}h2{color:#d4a843;margin-bottom:15px;}
        p{color:#888;margin-bottom:25px;}
        a{color:#d4a843;text-decoration:none;padding:10px 25px;border:1px solid #8b6914;
        border-radius:6px;margin:5px;display:inline-block;}a:hover{background:#3a2c10;}
        </style></head><body><div><h1>404</h1><h2>Страница не найдена</h2>
        <p>Запрошенная страница не существует.</p>
        <a href='?page=home'>🏠 Главная</a>".
        (isset($_SESSION['user_id'])
            ?"<a href='?page=profile'>👤 Профиль</a>"
            :"<a href='?page=login'>🔑 Войти</a>").
        "</div></body></html>";
        break;
}