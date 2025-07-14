<?php

namespace YetiSearch\Analyzers;

use YetiSearch\Contracts\AnalyzerInterface;
use YetiSearch\Stemmer\StemmerFactory;
use YetiSearch\Helpers\UTF8Helper as UTF8;

class StandardAnalyzer implements AnalyzerInterface
{
    private array $stopWords = [];
    private array $customStopWords = [];
    private array $stemmers = [];
    private array $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'min_word_length' => 2,
            'max_word_length' => 50,
            'remove_numbers' => false,
            'lowercase' => true,
            'strip_html' => true,
            'strip_punctuation' => true,
            'expand_contractions' => true,
            'custom_stop_words' => [],
            'disable_stop_words' => false
        ], $config);
        
        $this->loadStopWords();
        $this->setCustomStopWords($this->config['custom_stop_words']);
    }
    
    public function analyze(string $text, ?string $language = null): array
    {
        $language = $language ?? $this->detectLanguage($text);
        
        $originalText = $text;
        $text = $this->normalize($text);
        $tokens = $this->tokenize($text);
        $tokens = $this->removeStopWords($tokens, $language);
        
        $analyzed = [];
        foreach ($tokens as $token) {
            $stemmed = $this->stem($token, $language);
            if ($this->isValidToken($stemmed)) {
                $analyzed[] = $stemmed;
            }
        }
        
        return [
            'tokens' => $analyzed,
            'original' => $originalText,
            'language' => $language
        ];
    }
    
    public function tokenize(string $text): array
    {
        if ($this->config['strip_html']) {
            $text = strip_tags($text);
        }
        
        if ($this->config['expand_contractions']) {
            $text = $this->expandContractions($text);
        }
        
        if ($this->config['strip_punctuation']) {
            $text = preg_replace('/[^\p{L}\p{N}\s\'-]/u', ' ', $text);
        }
        
        if ($this->config['lowercase']) {
            $text = UTF8::strtolower($text);
        }
        
        $tokens = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        if ($this->config['remove_numbers']) {
            $tokens = array_filter($tokens, function ($token) {
                return !is_numeric($token);
            });
        }
        
        return array_values($tokens);
    }
    
    public function stem(string $word, ?string $language = null): string
    {
        $language = $language ?? 'english';
        
        if (!isset($this->stemmers[$language])) {
            try {
                $this->stemmers[$language] = StemmerFactory::create($language);
            } catch (\Exception $e) {
                $this->stemmers[$language] = StemmerFactory::create('english');
            }
        }
        
        return $this->stemmers[$language]->stem($word);
    }
    
    public function removeStopWords(array $tokens, ?string $language = null): array
    {
        if ($this->config['disable_stop_words']) {
            return $tokens;
        }
        
        $language = $language ?? 'english';
        $stopWords = $this->getStopWords($language);
        
        return array_values(array_filter($tokens, function ($token) use ($stopWords) {
            return !in_array(UTF8::strtolower($token), $stopWords);
        }));
    }
    
    public function normalize(string $text): string
    {
        $text = UTF8::normalize_whitespace($text);
        $text = UTF8::remove_invisible_characters($text);
        
        // Replace smart quotes and ellipsis
        $replacements = [
            "\u{201C}" => '"',  // Left double quotation mark
            "\u{201D}" => '"',  // Right double quotation mark
            "\u{2018}" => "'",  // Left single quotation mark
            "\u{2019}" => "'",  // Right single quotation mark
            "\u{2026}" => '...' // Horizontal ellipsis
        ];
        $text = str_replace(array_keys($replacements), array_values($replacements), $text);
        
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
    
    public function extractKeywords(string $text, int $limit = 10): array
    {
        $tokens = $this->analyze($text);
        $frequencies = array_count_values($tokens);
        
        arsort($frequencies);
        
        $keywords = [];
        $count = 0;
        
        foreach ($frequencies as $word => $frequency) {
            if ($count >= $limit) {
                break;
            }
            
            $keywords[] = [
                'word' => $word,
                'frequency' => $frequency,
                'score' => $this->calculateKeywordScore($word, $frequency, $text)
            ];
            
            $count++;
        }
        
        usort($keywords, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return $keywords;
    }
    
    private function detectLanguage(string $text): string
    {
        return 'english';
    }
    
    private function loadStopWords(): void
    {
        $this->stopWords = [
            'english' => [
                'a', 'about', 'above', 'after', 'again', 'against', 'all', 'am', 'an', 'and',
                'any', 'are', 'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below',
                'between', 'both', 'but', 'by', 'can', 'did', 'do', 'does', 'doing', 'down',
                'during', 'each', 'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having',
                'he', 'her', 'here', 'hers', 'herself', 'him', 'himself', 'his', 'how', 'i',
                'if', 'in', 'into', 'is', 'it', 'its', 'itself', 'just', 'me', 'more', 'most',
                'my', 'myself', 'no', 'nor', 'not', 'now', 'of', 'off', 'on', 'once', 'only',
                'or', 'other', 'our', 'ours', 'ourselves', 'out', 'over', 'own', 'same', 'she',
                'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them',
                'themselves', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to',
                'too', 'under', 'until', 'up', 'very', 'was', 'we', 'were', 'what', 'when',
                'where', 'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'would', 'you',
                'your', 'yours', 'yourself', 'yourselves'
            ],
            'french' => [
                'au', 'aux', 'avec', 'ce', 'ces', 'dans', 'de', 'des', 'du', 'elle', 'en', 'et',
                'eux', 'il', 'je', 'la', 'le', 'les', 'leur', 'lui', 'ma', 'mais', 'me', 'même', 'mes',
                'moi', 'mon', 'ne', 'nos', 'notre', 'nous', 'on', 'ou', 'par', 'pas', 'pour',
                'qu', 'que', 'qui', 'sa', 'se', 'ses', 'son', 'sur', 'ta', 'te', 'tes', 'toi',
                'ton', 'tu', 'un', 'une', 'vos', 'votre', 'vous', 'sont', 'est', 'été', 'être'
            ],
            'german' => [
                'aber', 'als', 'am', 'an', 'auch', 'auf', 'aus', 'bei', 'bin', 'bis', 'bist',
                'da', 'dadurch', 'daher', 'darum', 'das', 'daß', 'dass', 'dein', 'deine', 'dem',
                'den', 'der', 'des', 'dessen', 'deshalb', 'die', 'dies', 'dieser', 'dieses',
                'doch', 'dort', 'du', 'durch', 'ein', 'eine', 'einem', 'einen', 'einer', 'eines',
                'er', 'es', 'euer', 'eure', 'für', 'hatte', 'hatten', 'hattest', 'hattet', 'hier',
                'hinter', 'ich', 'ihr', 'ihre', 'im', 'in', 'ist', 'ja', 'jede', 'jedem', 'jeden',
                'jeder', 'jedes', 'jener', 'jenes', 'jetzt', 'kann', 'kannst', 'können', 'könnt',
                'machen', 'mein', 'meine', 'mit', 'muß', 'mußt', 'musst', 'müssen', 'müßt', 'nach',
                'nachdem', 'nein', 'nicht', 'nun', 'oder', 'seid', 'sein', 'seine', 'sich', 'sie',
                'sind', 'soll', 'sollen', 'sollst', 'sollt', 'sonst', 'soweit', 'sowie', 'und',
                'unser', 'unsere', 'unter', 'vom', 'von', 'vor', 'wann', 'warum', 'was', 'weiter',
                'weitere', 'wenn', 'wer', 'werde', 'werden', 'werdet', 'weshalb', 'wie', 'wieder',
                'wieso', 'wir', 'wird', 'wirst', 'wo', 'woher', 'wohin', 'zu', 'zum', 'zur', 'über'
            ],
            'spanish' => [
                'a', 'al', 'algo', 'algunas', 'algunos', 'ante', 'antes', 'como', 'con', 'contra',
                'cual', 'cuando', 'de', 'del', 'desde', 'donde', 'durante', 'e', 'el', 'ella',
                'ellas', 'ellos', 'en', 'entre', 'era', 'erais', 'eran', 'eras', 'eres', 'es',
                'esa', 'esas', 'ese', 'eso', 'esos', 'esta', 'estaba', 'estabais', 'estaban',
                'estabas', 'estad', 'estada', 'estadas', 'estado', 'estados', 'estamos', 'estando',
                'estar', 'estaremos', 'estará', 'estarán', 'estarás', 'estaré', 'estaréis',
                'estaría', 'estaríais', 'estaríamos', 'estarían', 'estarías', 'estas', 'este',
                'estemos', 'esto', 'estos', 'estoy', 'estuve', 'estuviera', 'estuvierais',
                'estuvieran', 'estuvieras', 'estuvieron', 'estuviese', 'estuvieseis', 'estuviesen',
                'estuvieses', 'estuvimos', 'estuviste', 'estuvisteis', 'estuviéramos',
                'estuviésemos', 'estuvo', 'está', 'estábamos', 'estáis', 'están', 'estás', 'esté',
                'estéis', 'estén', 'estés', 'fue', 'fuera', 'fuerais', 'fueran', 'fueras', 'fueron',
                'fuese', 'fueseis', 'fuesen', 'fueses', 'fui', 'fuimos', 'fuiste', 'fuisteis',
                'fuéramos', 'fuésemos', 'ha', 'habida', 'habidas', 'habido', 'habidos', 'habiendo',
                'habremos', 'habrá', 'habrán', 'habrás', 'habré', 'habréis', 'habría', 'habríais',
                'habríamos', 'habrían', 'habrías', 'habéis', 'había', 'habíais', 'habíamos',
                'habían', 'habías', 'han', 'has', 'hasta', 'hay', 'haya', 'hayamos', 'hayan',
                'hayas', 'hayáis', 'he', 'hemos', 'hube', 'hubiera', 'hubierais', 'hubieran',
                'hubieras', 'hubieron', 'hubiese', 'hubieseis', 'hubiesen', 'hubieses', 'hubimos',
                'hubiste', 'hubisteis', 'hubiéramos', 'hubiésemos', 'hubo', 'la', 'las', 'le',
                'les', 'lo', 'los', 'me', 'mi', 'mis', 'mucho', 'muchos', 'muy', 'más', 'mí',
                'mía', 'mías', 'mío', 'míos', 'nada', 'ni', 'no', 'nos', 'nosotras', 'nosotros',
                'nuestra', 'nuestras', 'nuestro', 'nuestros', 'o', 'os', 'otra', 'otras', 'otro',
                'otros', 'para', 'pero', 'poco', 'por', 'porque', 'que', 'quien', 'quienes',
                'qué', 'se', 'sea', 'seamos', 'sean', 'seas', 'seremos', 'será', 'serán', 'serás',
                'seré', 'seréis', 'sería', 'seríais', 'seríamos', 'serían', 'serías', 'seáis',
                'sido', 'siendo', 'sin', 'sobre', 'sois', 'somos', 'son', 'soy', 'su', 'sus',
                'suya', 'suyas', 'suyo', 'suyos', 'sí', 'también', 'tanto', 'te', 'tendremos',
                'tendrá', 'tendrán', 'tendrás', 'tendré', 'tendréis', 'tendría', 'tendríais',
                'tendríamos', 'tendrían', 'tendrías', 'tened', 'tenemos', 'tenga', 'tengamos',
                'tengan', 'tengas', 'tengo', 'tengáis', 'tenida', 'tenidas', 'tenido', 'tenidos',
                'teniendo', 'tenéis', 'tenía', 'teníais', 'teníamos', 'tenían', 'tenías', 'ti',
                'tiene', 'tienen', 'tienes', 'todo', 'todos', 'tu', 'tus', 'tuve', 'tuviera',
                'tuvierais', 'tuvieran', 'tuvieras', 'tuvieron', 'tuviese', 'tuvieseis',
                'tuviesen', 'tuvieses', 'tuvimos', 'tuviste', 'tuvisteis', 'tuviéramos',
                'tuviésemos', 'tuvo', 'tuya', 'tuyas', 'tuyo', 'tuyos', 'tú', 'un', 'una', 'uno',
                'unos', 'vosotras', 'vosotros', 'vuestra', 'vuestras', 'vuestro', 'vuestros', 'y',
                'ya', 'yo', 'él', 'éramos'
            ]
        ];
    }
    
    public function getStopWords(string $language): array
    {
        $defaultStopWords = $this->stopWords[$language] ?? $this->stopWords['english'];
        
        // Merge default stop words with custom stop words
        if (!empty($this->customStopWords)) {
            return array_unique(array_merge($defaultStopWords, $this->customStopWords));
        }
        
        return $defaultStopWords;
    }
    
    private function expandContractions(string $text): string
    {
        $contractions = [
            "can't" => "cannot",
            "won't" => "will not",
            "n't" => " not",
            "'re" => " are",
            "'ve" => " have",
            "'ll" => " will",
            "'d" => " would",
            "'m" => " am",
            "'s" => " is"
        ];
        
        return str_ireplace(array_keys($contractions), array_values($contractions), $text);
    }
    
    private function isValidToken(string $token): bool
    {
        $length = UTF8::strlen($token);
        
        return $length >= $this->config['min_word_length'] 
            && $length <= $this->config['max_word_length'];
    }
    
    private function calculateKeywordScore(string $word, int $frequency, string $text): float
    {
        $score = $frequency;
        
        if (UTF8::stripos($text, $word) < 100) {
            $score *= 1.5;
        }
        
        if (UTF8::strlen($word) > 6) {
            $score *= 1.2;
        }
        
        return $score;
    }
    
    public function setCustomStopWords(array $stopWords): void
    {
        $this->customStopWords = array_map(function ($word) {
            return UTF8::strtolower(trim($word));
        }, $stopWords);
    }
    
    public function addCustomStopWord(string $word): void
    {
        $word = UTF8::strtolower(trim($word));
        if (!in_array($word, $this->customStopWords)) {
            $this->customStopWords[] = $word;
        }
    }
    
    public function removeCustomStopWord(string $word): void
    {
        $word = UTF8::strtolower(trim($word));
        $this->customStopWords = array_values(array_filter($this->customStopWords, function ($stopWord) use ($word) {
            return $stopWord !== $word;
        }));
    }
    
    public function getCustomStopWords(): array
    {
        return $this->customStopWords;
    }
    
    public function isStopWordsDisabled(): bool
    {
        return $this->config['disable_stop_words'];
    }
    
    public function setStopWordsDisabled(bool $disabled): void
    {
        $this->config['disable_stop_words'] = $disabled;
    }
}