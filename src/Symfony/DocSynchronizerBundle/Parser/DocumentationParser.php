<?php

namespace Symfony\DocSynchronizerBundle\Parser;

use Gitonomy\Git\Blame;
use Gitonomy\Git\Blob;
use Gitonomy\Git\Repository;
use Gitonomy\Git\Tree;
use Symfony\DocSynchronizerBundle\Entity\Chapter;
use Symfony\DocSynchronizerBundle\Entity\Directory;
use Symfony\DocSynchronizerBundle\Entity\File;
use Symfony\DocSynchronizerBundle\Parser\DocumentParser;

class DocumentationParser
{
    private $currentRepository;
    private $currentCommit;

    /**
     * @return Directory
     */
    public function parse(Repository $repository, $version)
    {
        $dir = new Directory();

        $this->currentRepository = $repository;
        $this->currentCommit     = $repository->getRevision($version)->getCommit();

        $this->addChildren($dir, $this->currentCommit->getTree());

        return $dir;
    }

    private function addChildren(Directory $dir, Tree $tree, $path = '')
    {
        foreach ($tree->getEntries() as $name => $row) {
            if ($path === '') {
                $subpath = $name;
            } else {
                $subpath = $path.'/'.$name;
            }

            list($mode, $entry) = $row;

            if ($entry instanceof Blob) {
                $file = $dir->createFile($name);
                $this->parseDocument($file, $entry, $subpath);
            } elseif ($entry instanceof Tree) {
                $sub  = $dir->createDirectory($name);
                $this->addChildren($sub, $entry, $subpath);
            }
        }
    }

    private function parseDocument(File $file, Blob $blob, $path)
    {
        $name = basename($path);

        if (!preg_match('/\.(rst|rst\.inc)$/', $name)) {
            return;
        }

        $parser = new DocumentParser();
        $parser->parse($file, $blob->getContent());

        // max time
        $blame = $this->currentRepository->getBlame($this->currentCommit->getHash(), $path);
        $this->updateLastModified($file, $blame);
    }

    private function updateLastModified(File $file, Blame $blame)
    {
        foreach ($file->getChildren() as $child) {
            $this->updateLastModifiedChapter($child, $blame);
        }
    }

    private function updateLastModifiedChapter(Chapter $chapter, Blame $blame)
    {
        $start = $chapter->getLineStart();
        $end   = $chapter->getLineEnd();

        $max = new \DateTime('1970-01-01');
        $comm = null;
        for ($i = max(1, $start); $i <= $end; $i++) {
            $comm = $blame->getLine($i)->getCommit();

            $x = $comm->getCommitterDate();
            if ($x > $max) {
                $max = $x;
            }
        }

        if (!$comm) {
            throw new \RuntimeException('Error while blaming. : '.$start.'-'.$end);
        }

        $chapter->getLastModification()->setDate($comm->getCommitterDate());
        $chapter->getLastModification()->setAuthor($comm->getCommitterName());
        $chapter->getLastModification()->setMessage($comm->getShortMessage());
        $chapter->getLastModification()->setHash($comm->getHash());

        foreach ($chapter->getChildren() as $child) {
            $this->updateLastModifiedChapter($child, $blame);
        }
    }
}
