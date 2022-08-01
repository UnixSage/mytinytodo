<?php declare(strict_types=1);

/*
    This file is a part of myTinyTodo.
    (C) Copyright 2022 Max Pozdeev <maxpozdeev@gmail.com>
    Licensed under the GNU GPL version 2 or any later. See file COPYRIGHT for details.
*/

class ListsController extends ApiController {

    /**
     * Get all lists
     * @return array
     * @throws Exception
     */
    function get()
    {
        $db = DBConnection::instance();
        check_token();
        $t = array();
        $t['total'] = 0;
        if (!is_logged()) {
            $sqlWhere = 'WHERE published=1';
        }
        else {
            $sqlWhere = '';
            $t['list'][] = $this->prepareAllTasksList(); // show alltasks lists only for authorized user
            $t['total'] = 1;
        }
        $q = $db->dq("SELECT * FROM {$db->prefix}lists $sqlWhere ORDER BY ow ASC, id ASC");
        while ($r = $q->fetchAssoc())
        {
            $t['total']++;
            $t['list'][] = $this->prepareList($r);
        }
        return $t;
    }


    /**
     * Create new list
     * Code 201 on success
     * @return array
     * @throws Exception
     */
    function post()
    {
        checkWriteAccess();
        $db = DBConnection::instance();
        $t = array();
        $t['total'] = 0;
        $name = str_replace(
            array('"',"'",'<','>','&'),
            '',
            trim( $this->req->jsonBody['name'] ?? '' )
        );
        $ow = 1 + (int)$db->sq("SELECT MAX(ow) FROM {$db->prefix}lists");
        $db->dq("INSERT INTO {$db->prefix}lists (uuid,name,ow,d_created,d_edited,taskview) VALUES (?,?,?,?,?,?)",
                    array(generateUUID(), $name, $ow, time(), time(), 1) );
        $id = $db->lastInsertId();
        $t['total'] = 1;
        $r = $db->sqa("SELECT * FROM {$db->prefix}lists WHERE id=$id");
        $t['list'][] = $this->prepareList($r);
        return $t;
    }

    /**
     * Actions with all lists
     * @return array
     * @throws Exception
     */
    function put()
    {
        checkWriteAccess();
        $action = $this->req->jsonBody['action'] ?? '';
        switch ($action) {
            case 'order': return $this->changeListOrder(); break;
            default:      return ['total' => 0]; // error 400 ?
        }
    }


    /* Single list */

    /**
     * Get single list by Id
     * @param mixed $id
     * @return null|array
     * @throws Exception
     */
    function getId($id)
    {
        checkReadAccess($id);
        $db = DBConnection::instance();
        $r = $db->sqa( "SELECT * FROM {$db->prefix}lists WHERE id=?", array($id) );
        if (!$r) {
            return null;
        }
        $t = $this->prepareList($r);
        return $t;
    }

    /**
     * Delete list by Id
     * @param mixed $id
     * @return array
     * @throws Exception
     */
    function deleteId($id)
    {
        checkWriteAccess();
        $db = DBConnection::instance();
        $t = array();
        $t['total'] = 0;
        $id = (int)$id;
        $db->ex("BEGIN");
        $db->ex("DELETE FROM {$db->prefix}lists WHERE id=$id");
        $t['total'] = $db->affected();
        if ($t['total']) {
            $db->ex("DELETE FROM {$db->prefix}tag2task WHERE list_id=$id");
            $db->ex("DELETE FROM {$db->prefix}todolist WHERE list_id=$id");
        }
        $db->ex("COMMIT");
        return $t;
    }


    /**
     * Edit some properties of List
     * Actions: rename
     * @param mixed $id
     * @return array
     * @throws Exception
     */
    function putId($id)
    {
        checkWriteAccess();
        $id = (int)$id;

        $action = $this->req->jsonBody['action'] ?? '';
        switch ($action) {
            case 'rename':         return $this->renameList($id);     break;
            case 'sort':           return $this->sortList($id);       break;
            case 'publish':        return $this->publishList($id);    break;
            case 'showNotes':      return $this->showNotes($id);      break;
            case 'hide':           return $this->hideList($id);       break;
            case 'clearCompleted': return $this->clearCompleted($id); break;
            default:               return ['total' => 0];
        }
    }


    /* Private Functions */

    private function prepareAllTasksList()
    {
        //default values
        $hidden = 1;
        $sort = 3;
        $showCompleted = 1;

        $opts = Config::requestDomain('alltasks.json');
        if ( isset($opts['hidden']) ) $hidden = (int)$opts['hidden'] ? 1 : 0;
        if ( isset($opts['sort']) ) $sort = (int)$opts['sort'];
        if ( isset($opts['showCompleted']) ) $showCompleted = (int)$opts['showCompleted'];

        return array(
            'id' => -1,
            'name' => htmlarray(__('alltasks')),
            'sort' => $sort,
            'published' => 0,
            'showCompl' => $showCompleted,
            'showNotes' => 0,
            'hidden' => $hidden,
        );
    }

    private function prepareList($row)
    {
        $taskview = (int)$row['taskview'];
        return array(
            'id' => $row['id'],
            'name' => htmlarray($row['name']),
            'sort' => (int)$row['sorting'],
            'published' => $row['published'] ? 1 :0,
            'showCompl' => $taskview & 1 ? 1 : 0,
            'showNotes' => $taskview & 2 ? 1 : 0,
            'hidden' => $taskview & 4 ? 1 : 0,
        );
    }

    private function renameList(int $id)
    {
        $db = DBConnection::instance();
        $t = array();
        $t['total'] = 0;
        $name = str_replace(
            array('"',"'",'<','>','&'),
            array('','','','',''),
            trim($this->req->jsonBody['name'] ?? '')
        );
        $db->dq("UPDATE {$db->prefix}lists SET name=?,d_edited=? WHERE id=$id", array($name, time()) );
        $t['total'] = $db->affected();
        $r = $db->sqa("SELECT * FROM {$db->prefix}lists WHERE id=$id");
        $t['list'][] = $this->prepareList($r);
        return $t;
    }

    private function sortList(int $listId)
    {
        $sort = (int)($this->req->jsonBody['sort'] ?? 0);
        self::setListSortingById($listId, $sort);
        return ['total'=>1];
    }

    static function setListSortingById(int $listId, int $sort)
    {
        $db = DBConnection::instance();
        if ($sort < 0 || $sort > 104) $sort = 0;
        elseif ($sort < 101 && $sort > 4) $sort = 0;
        if ($listId == -1) {
            $opts = Config::requestDomain('alltasks.json');
            $opts['sort'] = $sort;
            Config::saveDomain('alltasks.json', $opts);
        }
        else {
            $db->ex("UPDATE {$db->prefix}lists SET sorting=$sort,d_edited=? WHERE id=$listId", array(time()));
        }
    }

    static function setListShowCompletedById(int $listId, bool $showCompleted)
    {
        $db = DBConnection::instance();
        if ($listId == -1) {
            $opts = Config::requestDomain('alltasks.json');
            $opts['showCompleted'] = (int)$showCompleted;
            Config::saveDomain('alltasks.json', $opts);
        }
        else {
            $bitwise = $showCompleted ? 'taskview & ~1' : 'taskview | 1';
            $db->dq("UPDATE {$db->prefix}lists SET taskview=$bitwise WHERE id=?", [$listId]);
        }
    }

    private function publishList(int $listId)
    {
        $db = DBConnection::instance();
        $publish = (int)($this->req->jsonBody['publish'] ?? 0);
        $db->ex("UPDATE {$db->prefix}lists SET published=?,d_created=? WHERE id=$listId", array($publish ? 1 : 0, time()));
        return ['total'=>1];
    }

    private function showNotes(int $listId)
    {
        $db = DBConnection::instance();
        $flag = (int)($this->req->jsonBody['shownotes'] ?? 0);
        $bitwise = ($flag == 0) ? 'taskview & ~2' : 'taskview | 2';
        $db->dq("UPDATE {$db->prefix}lists SET taskview=$bitwise WHERE id=$listId");
        return ['total'=>1];
    }

    private function hideList(int $listId)
    {
        $db = DBConnection::instance();
        $flag = (int)($this->req->jsonBody['hide'] ?? 0);
        if ($listId == -1) {
            $opts = Config::requestDomain('alltasks.json');
            $opts['hidden'] = $flag ? 1 : 0;
            Config::saveDomain('alltasks.json', $opts);
        }
        else {
            $bitwise = ($flag == 0) ? 'taskview & ~4' : 'taskview | 4';
            $db->dq("UPDATE {$db->prefix}lists SET taskview=$bitwise WHERE id=$listId");
        }
        return ['total'=>1];
    }

    private function clearCompleted(int $listId)
    {
        $db = DBConnection::instance();
        $t = array();
        $t['total'] = 0;
        $db->ex("BEGIN");
        $db->ex("DELETE FROM {$db->prefix}tag2task WHERE task_id IN (SELECT id FROM {$db->prefix}todolist WHERE list_id=? and compl=1)", array($listId));
        $db->ex("DELETE FROM {$db->prefix}todolist WHERE list_id=$listId and compl=1");
        $t['total'] = $db->affected();
        $db->ex("COMMIT");
        return $t;
    }

    private function changeListOrder()
    {
        $t = array();
        $t['total'] = 0;
        if (!is_array($this->req->jsonBody['order'])) {
            return $t;
        }
        $db = DBConnection::instance();
        $order = $this->req->jsonBody['order'];
        $a = array();
        $setCase = '';
        foreach ($order as $ow => $id) {
            $id = (int)$id;
            $a[] = $id;
            $setCase .= "WHEN id=$id THEN $ow\n";
        }
        $ids = implode(',', $a);
        $db->dq("UPDATE {$db->prefix}lists SET d_edited=?, ow = CASE\n $setCase END WHERE id IN ($ids)",
                    array(time()) );
        $t['total'] = 1;
        return $t;
    }

}
