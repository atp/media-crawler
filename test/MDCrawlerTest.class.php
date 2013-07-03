<?php

require_once('mdcrawler.class.php');

class MDCrawlerTest extends PHPUnit_Framework_TestCase {
    
    public function testGetStringDiffs() {
        $result = getStringDiffs('string', 'string');
        $this->assertEquals(array('', ''), $result);
        
        $result = getStringDiffs('prefix_diff1_sufix', 'prefixdiff2sufix');
        $this->assertEquals(array('_diff1_', 'diff2'), $result);
        
        $result = getStringDiffs('string', 'anotherstring');
        $this->assertEquals(array('', 'another'), $result);
        
        $result = getStringDiffs('onestring', 'string');
        $this->assertEquals(array('one', ''), $result);
        
        $result = getStringDiffs('input_string_1', 'another_string');
        $this->assertEquals(array('input_string_1', 'another_string'), $result);
    }
    
    public function testSearchVersionOf() {
        $metadatas = array();
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo1.flv'));
        $metadata->id = 'http://www.example.org/Capitulo1.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo2.flv'));
        $metadata->id = 'http://www.example.org/Capitulo2.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo3.flv'));
        $metadata->id = 'http://www.example.org/Capitulo3.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo4.flv'));
        $metadata->id = 'http://www.example.org/Capitulo4.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo5.flv'));
        $metadata->id = 'http://www.example.org/Capitulo5.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo6.flv'));
        $metadata->id = 'http://www.example.org/Capitulo6.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo7.flv'));
        $metadata->id = 'http://www.example.org/Capitulo7.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo7_old.flv'));
        $metadata->id = 'http://www.example.org/Capitulo7_old.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo8.flv'));
        $metadata->id = 'http://www.example.org/Capitulo8.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo8f.flv'));
        $metadata->id = 'http://www.example.org/Capitulo8f.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo8m.flv'));
        $metadata->id = 'http://www.example.org/Capitulo8m.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo8s.flv'));
        $metadata->id = 'http://www.example.org/Capitulo8s.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo9.flv'));
        $metadata->id = 'http://www.example.org/Capitulo9.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo9f.flv'));
        $metadata->id = 'http://www.example.org/Capitulo9f.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo9m.flv'));
        $metadata->id = 'http://www.example.org/Capitulo9m.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo9s.flv'));
        $metadata->id = 'http://www.example.org/Capitulo9s.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo10m.flv'));
        $metadata->id = 'http://www.example.org/Capitulo10m.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'Capitulo10s.flv'));
        $metadata->id = 'http://www.example.org/Capitulo10s.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'T4_Highlights_Capitulo5.flv'));
        $metadata->id = 'http://www.example.org/T4_Highlights_Capitulo5.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'T4_Highlights_Capitulo6e7.flv'));
        $metadata->id = 'http://www.example.org/T4_Highlights_Capitulo6e7.flv';
        $metadatas[$metadata->id] = $metadata;
        
        $metadata = new Metadata(new Resource('http://www.example.org/', 'T4_Highlights_Capitulo8e9.flv'));
        $metadata->id = 'http://www.example.org/T4_Highlights_Capitulo8e9.flv';
        $metadatas[$metadata->id] = $metadata;
                
        $metadata = new Metadata(new Resource('http://www.example.org/', 'T4_Highlights_Capitulo10.flv'));
        $metadata->id = 'http://www.example.org/T4_Highlights_Capitulo10.flv';
        $metadatas[$metadata->id] = $metadata;
         
        $crawler = new MDCrawler();
        $crawler->metadatas = $metadatas;
        $crawler->setMultipleVersions(array('#file'=>array('_old', 'f', 'm', 's')));
         
        $foundMetadatas = $crawler->searchVersionOf($metadatas['http://www.example.org/Capitulo1.flv']);
        $this->assertEmpty($foundMetadatas);
    
        $foundMetadatas = $crawler->searchVersionOf($metadatas['http://www.example.org/Capitulo7.flv']);
        $this->assertNotEmpty($foundMetadatas);
        $this->assertEquals(1, count($foundMetadatas));
        $this->assertTrue(in_array($metadatas['http://www.example.org/Capitulo7_old.flv'], $foundMetadatas));
     
        $foundMetadatas = $crawler->searchVersionOf($metadatas['http://www.example.org/Capitulo9.flv']);
        $this->assertNotEmpty($foundMetadatas);
        $this->assertEquals(3, count($foundMetadatas));
        $this->assertTrue(in_array($metadatas['http://www.example.org/Capitulo9f.flv'], $foundMetadatas));
        $this->assertTrue(in_array($metadatas['http://www.example.org/Capitulo9m.flv'], $foundMetadatas));
        $this->assertTrue(in_array($metadatas['http://www.example.org/Capitulo9s.flv'], $foundMetadatas));

        $foundMetadatas = $crawler->searchVersionOf($metadatas['http://www.example.org/Capitulo10s.flv']);
        $this->assertNotEmpty($foundMetadatas);
        $this->assertEquals(1, count($foundMetadatas));
        $this->assertTrue(in_array($metadatas['http://www.example.org/Capitulo10m.flv'], $foundMetadatas));
    }
     
    public function testVazio() {
        $pilha = array();
        $this->assertEmpty($pilha);
        return $pilha;
    }

    /**
     * @depends testVazio
     */
    public function testPush(array $pilha) {
        array_push($pilha, 'foo');
        $this->assertEquals('foo', $pilha[count($pilha)-1]);
        $this->assertNotEmpty($pilha);
        return $pilha;
    }

    /**
     * @depends testPush
     */
    public function testPop(array $pilha) {
        $this->assertEquals('foo', array_pop($pilha));
        $this->assertEmpty($pilha);
    }
    
}

?>
