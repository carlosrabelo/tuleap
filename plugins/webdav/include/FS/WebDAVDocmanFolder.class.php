<?php
/**
 * Copyright (c) STMicroelectronics, 2010. All Rights Reserved.
 *
 * This file is a part of Codendi.
 *
 * Codendi is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Codendi is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Codendi. If not, see <http://www.gnu.org/licenses/>.
 */

require_once (dirname(__FILE__).'/../../../docman/include/Docman_ItemFactory.class.php');
require_once (dirname(__FILE__).'/../../../docman/include/Docman_FileStorage.class.php');
require_once ('WebDAVDocmanDocument.class.php');
require_once ('WebDAVDocmanFile.class.php');

/**
 * This class Represents Docman folders in WebDAV
 *
 * It's an implementation of the abstract class Sabre_DAV_Directory methods
 */
class WebDAVDocmanFolder extends Sabre_DAV_Directory {

    private $user;
    private $project;
    private $item;
    private static $maxFileSize;

    /**
     * Constructor of the class
     *
     * @param User $user
     * @param Project $project
     * @param Docman_Folder $item
     *
     * @return void
     */
    function __construct($user, $project, $item) {
        $this->user = $user;
        $this->project = $project;
        $this->item = $item;
    }

    /**
     * Returns the max file size
     *
     * @return Integer
     */
    function getMaxFileSize() {
        return self::$maxFileSize;
    }

    /**
     * Sets the max file size
     *
     * @param Integer $maxFileSize
     *
     * @return void
     */
    function setMaxFileSize($maxFileSize) {
        self::$maxFileSize = $maxFileSize;
    }

    /**
     * Returns the content of the folder
     * including indication about duplicate entries
     *
     * @return Array
     */
    function getChildList() {
        $children = array();
        // hey ! for docman never add something in WebDAVUtils, docman may be not present ;)
        $docmanItemFactory = $this->getUtils()->getDocmanItemFactory();
        $nodes = $docmanItemFactory->getChildrenFromParent($this->getItem());
        $docmanPermissionManager = $this->getUtils()->getDocmanPermissionsManager($this->getProject());

        foreach ($nodes as $node) {
            if ($docmanPermissionManager->userCanAccess($this->getUser(), $node->getId())) {
                $class = get_class($node);
                switch ($class) {
                    case 'Docman_File':
                        $item = $docmanItemFactory->getItemFromDb($node->getId());
                        $version = $item->getCurrentVersion();
                        $index = $version->getFilename();
                        $method = 'getWebDAVDocmanFile';
                        break;
                    case 'Docman_EmbeddedFile':
                        $index = $node->getTitle();
                        $method = 'getWebDAVDocmanFile';
                        break;
                    case 'Docman_Empty':
                    case 'Docman_Wiki':
                    case 'Docman_Link':
                        $index = $node->getTitle();
                        $method = 'getWebDAVDocmanDocument';
                        break;
                    default:
                        $index = $node->getTitle();
                        $method = 'getWebDAVDocmanFolder';
                        break;
                }

                // When it's a duplicate say it, so it can be processed later
                foreach ($children as $key => $value) {
                    if (strcasecmp($key, $index) === 0) {
                        $children[$key]   = 'duplicate';
                        $children[$index] = 'duplicate';
                    }
                }
                if (!isset($children[$index])) {
                    $children[$index] = call_user_func(array($this,$method), $node);
                }
            }
        }
        return $children;
    }

    /**
     * Returns the visible content of the folder
     *
     * @return Array
     *
     * @see plugins/webdav/include/lib/Sabre/DAV/Sabre_DAV_ICollection::getChildren()
     */
    function getChildren() {
        $children = $this->getChildList();
        // Remove all duplicate elements
        foreach ($children as $key => $node) {
            if ($node === 'duplicate') {
                unset($children[$key]);
            }
        }
        return $children;
    }

    /**
     * Returns the given node
     *
     * @param String $name
     *
     * @return Docman_Item
     *
     * @see plugins/webdav/include/lib/Sabre/DAV/Sabre_DAV_Directory::getChild()
     */
    function getChild($name) {
        $name = $this->getUtils()->retrieveName($name);
        $children = $this->getChildList();

        if (!isset($children[$name])) {
            throw new Sabre_DAV_Exception_FileNotFound($GLOBALS['Language']->getText('plugin_webdav_common', 'docman_item_not_available'));
        } elseif ($children[$name] === 'duplicate') {
            throw new Sabre_DAV_Exception_Conflict($GLOBALS['Language']->getText('plugin_webdav_common', 'docman_item_duplicated'));
        } else {
            return $children[$name];
        }
    }

    /**
     * Returns the name of the folder
     *
     * @return String
     *
     * @see plugins/webdav/include/lib/Sabre/DAV/Sabre_DAV_INode::getName()
     */
    function getName() {
        if ($this->isDocmanRoot()) {
            // case of the root
            return 'Documents';
        }
        $utils = $this->getUtils();
        return $utils->unconvertHTMLSpecialChars($this->getItem()->getTitle());
    }

    /**
     * Returns the last modification date
     *
     * @return date
     *
     * @see plugins/webdav/include/lib/Sabre/DAV/Sabre_DAV_Node::getLastModified()
     */
    function getLastModified() {
        return $this->getItem()->getUpdateDate();
    }

    /**
     * Returns the represented folder
     *
     * @return Docman_Folder
     */
    function getItem() {
        return $this->item;
    }

    /**
     * Returns the project
     *
     * @return Project
     */
    function getProject() {
        return $this->project;
    }

    /**
     * Returns the user
     *
     * @return User
     */
    function getUser() {
        return $this->user;
    }

    /**
     * Returns an instance of WebDAVUtils
     *
     * @return WebDAVUtils
     */
    function getUtils() {
        return WebDAVUtils::getInstance();
    }

    /**
     * Tell if the folder is docman root
     *
     * @return Boolean
     */
    function isDocmanRoot() {
        return !$this->getItem()->getParentId();
    }

    /**
     * Returns a new WebDAVDocmanFile
     *
     * @params Docman_File $item
     *
     * @return WebDAVDocmanFile
     */
    function getWebDAVDocmanFile($item) {
        return new WebDAVDocmanFile($this->user, $this->getProject(), $item);
    }

    /**
     * Returns a new WebDAVDocmanEmpty
     *
     * @params mixed $item
     *
     * @return WebDAVDocmanEmpty
     */
    function getWebDAVDocmanDocument($item) {
        return new WebDAVDocmanDocument($this->user, $this->getProject(), $item);
    }

    /**
     * Returns a new WebDAVDocmanFolder
     *
     * @params Docman_Folder $folder
     *
     * @return WebDAVDocmanFolder
     */
    function getWebDAVDocmanFolder($folder) {
        return new WebDAVDocmanFolder($this->user, $this->getProject(), $folder);
    }

    /**
     * Create a new docman folder
     *
     * @param String $name Name of the folder to create
     *
     * @return void
     */
    function createDirectory($name) {
        $docmanPermissionManager = $this->getUtils()->getDocmanPermissionsManager($this->getProject());
        if ($this->getUtils()->isWriteEnabled() && $docmanPermissionManager->userCanWrite($this->getUser(), $this->getItem()->getId())) {
            $item['item_type']         = PLUGIN_DOCMAN_ITEM_TYPE_FOLDER;
            $item['user_id']           = $this->getUser()->getId();
            $item['group_id']          = $this->getProject()->getGroupId();
            $item['parent_id']         = $this->getItem()->getId();
            $item['title']             = htmlspecialchars($name);
            $itemFactory               = $this->getUtils()->getDocmanItemFactory();
            $id                        = $itemFactory->create($item, 'beginning');
            if ($id) {
                $newItem  = $itemFactory->getItemFromDb($id);
                $parent   = $itemFactory->getItemFromDb($item['parent_id']);
                $event    = 'plugin_docman_event_add';
                $newItem->fireEvent($event, $this->getUser(), $parent);
                $this->cloneItemPermissions($item['parent_id'], $id);
            }
        } else {
            throw new Sabre_DAV_Exception_Forbidden($GLOBALS['Language']->getText('plugin_webdav_common', 'folder_denied_create'));
        }
    }

    /**
     * Inherit permissions from parent
     *
     * @param Integer $parentId Id of the parent item
     * @param Integer $itemId   Id of the new item
     *
     * @return void
     */
    function cloneItemPermissions($parentId, $itemId) {
        $pm = $this->getUtils()->getPermissionsManager();
        $pm->clonePermissions($parentId, $itemId, array('PLUGIN_DOCMAN_READ', 'PLUGIN_DOCMAN_WRITE', 'PLUGIN_DOCMAN_MANAGE'));
    }

    /**
     * Rename a docman folder
     *
     * Even if rename is forbidden some silly WebDAV clients (ie : Micro$oft's one)
     * will bypass that and try to delete the original directory
     * then upload another one with the same content and a new name
     * Which is very different from just renaming the directory
     *
     * @param String $name New name of the folder
     *
     * @return void
     */
    function setName($name) {
        $docmanPermissionManager = $this->getUtils()->getDocmanPermissionsManager($this->getProject());
        if ($this->getUtils()->isWriteEnabled() && !$this->isDocmanRoot() && $docmanPermissionManager->userCanWrite($this->getUser(), $this->getItem()->getId())) {
            $row          = $this->getItem()->toRow();
            $row['title'] = htmlspecialchars($name);
            $row['id']    = $this->getItem()->getId();
            $itemFactory  = $this->getUtils()->getDocmanItemFactory();
            $itemFactory->update($row);
        } else {
            throw new Sabre_DAV_Exception_MethodNotAllowed($GLOBALS['Language']->getText('plugin_webdav_common', 'folder_denied_rename'));
        }
    }

    /**
     * Creates a new document under the folder
     *
     * @param String $name Name of the document
     * @param Binary $data Content of the document
     *
     * @return void
     */
    function createFile($name, $data = null) {
        $docmanPermissionManager = $this->getUtils()->getDocmanPermissionsManager($this->getProject());
        if ($this->getUtils()->isWriteEnabled() && $docmanPermissionManager->userCanWrite($this->getUser(), $this->getItem()->getId())) {
            $content = stream_get_contents($data);
            if (strlen($content) <= $this->getMaxFileSize()) {
                $item['item_type'] = PLUGIN_DOCMAN_ITEM_TYPE_FILE;
                $item['user_id']   = $this->getUser()->getId();
                $item['group_id']  = $this->getProject()->getGroupId();
                $item['parent_id'] = $this->getItem()->getId();
                $item['title']     = htmlspecialchars($name);
                $itemFactory       = $this->getUtils()->getDocmanItemFactory();
                $id                = $itemFactory->create($item, 'beginning');
                if ($id) {
                    $newItem           = $itemFactory->getItemFromDb($id);
                    $parent           = $itemFactory->getItemFromDb($item['parent_id']);
                    $event    = 'plugin_docman_event_add';
                    $newItem->fireEvent($event, $this->getUser(), $parent);
                    $versionFactory    = $this->getUtils()->getVersionFactory();
                    $nextNb            = $versionFactory->getNextVersionNumber($newItem);
                    if($nextNb === false) {
                        $number       = 1;
                        $_changelog   = 'Initial version';
                    } else {
                        $number       = $nextNb;
                        $_changelog   = '';
                    }
                    $this->cloneItemPermissions($item['parent_id'], $id);
                    $fs   = $this->getUtils()->getFileStorage();
                    $path = $fs->store($content, $this->getProject()->getGroupId(), $newItem->getId(), $number);
                    if ($path) {
                        $_filesize      = PHP_BigFile::getSize($path);
                        $_filename      = htmlspecialchars($name);
                        $_filetype      = mime_content_type($path);
                        $vArray         = array('item_id'   => $newItem->getId(),
                                                'number'    => $number,
                                                'user_id'   => $this->getUser()->getId(),
                                                'label'     => '',
                                                'changelog' => $_changelog,
                                                'filename'  => $_filename,
                                                'filesize'  => $_filesize,
                                                'filetype'  => $_filetype, 
                                                'path'      => $path,
                                                'date'      => '');
                        if (!$versionFactory->create($vArray)) {
                            throw new WebDAVExceptionServerError($GLOBALS['Language']->getText('plugin_webdav_upload', 'create_file_fail'));
                        }
                    } else {
                        throw new WebDAVExceptionServerError($GLOBALS['Language']->getText('plugin_webdav_upload', 'write_file_fail'));
                    }
                } else {
                    throw new WebDAVExceptionServerError($GLOBALS['Language']->getText('plugin_webdav_upload', 'create_file_fail'));
                }
            } else {
                throw new Sabre_DAV_Exception_RequestedRangeNotSatisfiable($GLOBALS['Language']->getText('plugin_webdav_download', 'error_file_size'));
            }
        } else {
            throw new Sabre_DAV_Exception_Forbidden($GLOBALS['Language']->getText('plugin_webdav_common', 'file_denied_create'));
        }
    }

    function deleteDirectoryContent($item) {
        $docmanPermissionManager = $this->getUtils()->getDocmanPermissionsManager($this->getProject());
        if ($docmanPermissionManager->userCanWrite($this->getUser(), $this->getItem()->getId())) {
            $itemFactory  = $this->getUtils()->getDocmanItemFactory();
            $allChildrens = $itemFactory->getChildrenFromParent($item);
            foreach($allChildrens as $child) {
                if (get_class($child) == 'Docman_File' || get_class($child) == 'Docman_EmbeddedFile') {
                    $docmanFile = $this->getWebDAVDocmanFile($child);
                    $docmanFile->delete();
                }
                else if(get_class($child)=='Docman_Folder') {
                    $this->deleteDirectoryContent($child);
                    $child->delete();
                }
            }
        } else {
            throw new Sabre_DAV_Exception($GLOBALS['Language']->getText('plugin_webdav_common', 'file_denied_delete'));
        }
    }

    /**
     * Deletes a docman folder and its content
     *
     * @return void
     */
    function delete() {
        $docmanPermissionManager = $this->getUtils()->getDocmanPermissionsManager($this->getProject());

            if ($this->getUtils()->isWriteEnabled() && !$this->isDocmanRoot() && $docmanPermissionManager->userCanWrite($this->getUser(), $this->getItem()->getId())) {
                $item = $this->getItem();
                $itemFactory  = $this->getUtils()->getDocmanItemFactory();
                $subItemsWritable = $docmanPermissionManager->currentUserCanWriteSubItems($item->getId());
                if($subItemsWritable) {
                    $this->deleteDirectoryContent($item);
                    $itemFactory->delete($item);
                } else {
                    throw new Sabre_DAV_Exception_MethodNotAllowed($GLOBALS['Language']->getText('plugin_webdav_common', 'error_subitems_not_deleted_no_w'));
                }
            } else {
                throw new Sabre_DAV_Exception_Forbidden($GLOBALS['Language']->getText('plugin_webdav_common', 'file_denied_delete'));
            }
        }
}

?>