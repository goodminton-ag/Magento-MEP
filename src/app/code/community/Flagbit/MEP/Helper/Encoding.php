<?php

class Flagbit_MEP_Helper_Encoding extends Mage_Core_Helper_Abstract {


    protected $_htmlEntities = array('&Acirc;' => 'Â', '&acirc;' => 'â', '&acute;' => '´', '&AElig;' => 'Æ', '&aelig;' => 'æ', '&Agrave;' => 'À', '&agrave;' => 'à', '&alefsym;' => 'ℵ', '&Alpha;' => 'Α', '&alpha;' => 'α', '&amp;' => '&', '&and;' => '∧', '&ang;' => '∠', '&apos;' => '\'', '&Aring;' => 'Å', '&aring;' => 'å', '&asymp;' => '≈', '&Atilde;' => 'Ã', '&atilde;' => 'ã', '&Auml;' => 'Ä', '&auml;' => 'ä', '&bdquo;' => '„', '&Beta;' => 'Β', '&beta;' => 'β', '&brvbar;' => '¦', '&bull;' => '•', '&cap;' => '∩', '&Ccedil;' => 'Ç', '&ccedil;' => 'ç', '&cedil;' => '¸', '&cent;' => '¢', '&Chi;' => 'Χ', '&chi;' => 'χ', '&circ;' => 'ˆ', '&clubs;' => '♣', '&cong;' => '≅', '&copy;' => '©', '&crarr;' => '↵', '&cup;' => '∪', '&curren;' => '¤', '&Dagger;' => '‡', '&dagger;' => '†', '&dArr;' => '⇓', '&darr;' => '↓', '&deg;' => '°', '&Delta;' => 'Δ', '&delta;' => 'δ', '&diams;' => '♦', '&divide;' => '÷', '&Eacute;' => 'É', '&eacute;' => 'é', '&Ecirc;' => 'Ê', '&ecirc;' => 'ê', '&Egrave;' => 'È', '&egrave;' => 'è', '&empty;' => '∅', '&emsp;' => ' ', '&ensp;' => ' ', '&Epsilon;' => 'Ε', '&epsilon;' => 'ε', '&equiv;' => '≡', '&Eta;' => 'Η', '&eta;' => 'η', '&ETH;' => 'Ð', '&eth;' => 'ð', '&Euml;' => 'Ë', '&euml;' => 'ë', '&euro;' => '€', '&exist;' => '∃', '&fnof;' => 'ƒ', '&forall;' => '∀', '&frac12;' => '½', '&frac14;' => '¼', '&frac34;' => '¾', '&frasl;' => '⁄', '&Gamma;' => 'Γ', '&gamma;' => 'γ', '&ge;' => '≥', '&gt;' => '>', '&hArr;' => '⇔', '&harr;' => '↔', '&hearts;' => '♥', '&hellip;' => '…', '&Iacute;' => 'Í', '&iacute;' => 'í', '&Icirc;' => 'Î', '&icirc;' => 'î', '&iexcl;' => '¡', '&Igrave;' => 'Ì', '&igrave;' => 'ì', '&image;' => 'ℑ', '&infin;' => '∞', '&int;' => '∫', '&Iota;' => 'Ι', '&iota;' => 'ι', '&iquest;' => '¿', '&isin;' => '∈', '&Iuml;' => 'Ï', '&iuml;' => 'ï', '&Kappa;' => 'Κ', '&kappa;' => 'κ', '&Lambda;' => 'Λ', '&lambda;' => 'λ', '&lang;' => '⟨', '&laquo;' => '«', '&lArr;' => '⇐', '&larr;' => '←', '&lceil;' => '⌈', '&ldquo;' => '“', '&le;' => '≤', '&lfloor;' => '⌊', '&lowast;' => '∗', '&loz;' => '◊', '&lrm;' => '‎', '&lsaquo;' => '‹', '&lsquo;' => '‘', '&lt;' => '<', '&macr;' => '¯', '&mdash;' => '—', '&micro;' => 'µ', '&middot;' => '·', '&minus;' => '−', '&Mu;' => 'Μ', '&mu;' => 'μ', '&nabla;' => '∇', '&nbsp;' => ' ', '&ndash;' => '–', '&ne;' => '≠', '&ni;' => '∋', '&not;' => '¬', '&notin;' => '∉', '&nsub;' => '⊄', '&Ntilde;' => 'Ñ', '&ntilde;' => 'ñ', '&Nu;' => 'Ν', '&nu;' => 'ν', '&Oacute;' => 'Ó', '&oacute;' => 'ó', '&Ocirc;' => 'Ô', '&ocirc;' => 'ô', '&OElig;' => 'Œ', '&oelig;' => 'œ', '&Ograve;' => 'Ò', '&ograve;' => 'ò', '&oline;' => '‾', '&Omega;' => 'Ω', '&omega;' => 'ω', '&Omicron;' => 'Ο', '&omicron;' => 'ο', '&oplus;' => '⊕', '&or;' => '∨', '&ordf;' => 'ª', '&ordm;' => 'º', '&Oslash;' => 'Ø', '&oslash;' => 'ø', '&Otilde;' => 'Õ', '&otilde;' => 'õ', '&otimes;' => '⊗', '&Ouml;' => 'Ö', '&ouml;' => 'ö', '&para;' => '¶', '&part;' => '∂', '&permil;' => '‰', '&perp;' => '⊥', '&Phi;' => 'Φ', '&phi;' => 'φ', '&Pi;' => 'Π', '&pi;' => 'π', '&piv;' => 'ϖ', '&plusmn;' => '±', '&pound;' => '£', '&Prime;' => '″', '&prime;' => '′', '&prod;' => '∏', '&prop;' => '∝', '&Psi;' => 'Ψ', '&psi;' => 'ψ', '&quot;' => '""""', '&radic;' => '√', '&rang;' => '⟩', '&raquo;' => '»', '&rArr;' => '⇒', '&rarr;' => '→', '&rceil;' => '⌉', '&rdquo;' => '”', '&real;' => 'ℜ', '&reg;' => '®', '&rfloor;' => '⌋', '&Rho;' => 'Ρ', '&rho;' => 'ρ', '&rlm;' => '‏', '&rsaquo;' => '›', '&rsquo;' => '’', '&sbquo;' => '‚', '&Scaron;' => 'Š', '&scaron;' => 'š', '&sdot;' => '⋅', '&sect;' => '§', '&shy;' => '­', '&Sigma;' => 'Σ', '&sigma;' => 'σ', '&sigmaf;' => 'ς', '&sim;' => '∼', '&spades;' => '♠', '&sub;' => '⊂', '&sube;' => '⊆', '&sum;' => '∑', '&sup;' => '⊃', '&sup1;' => '¹', '&sup2;' => '²', '&sup3;' => '³', '&supe;' => '⊇', '&szlig;' => 'ß', '&Tau;' => 'Τ', '&tau;' => 'τ', '&there4;' => '∴', '&Theta;' => 'Θ', '&theta;' => 'θ', '&thetasym;' => 'ϑ', '&thinsp;' => ' ', '&THORN;' => 'Þ', '&thorn;' => 'þ', '&tilde;' => '˜', '&times;' => '×', '&trade;' => '™', '&Uacute;' => 'Ú', '&uacute;' => 'ú', '&uArr;' => '⇑', '&uarr;' => '↑', '&Ucirc;' => 'Û', '&ucirc;' => 'û', '&Ugrave;' => 'Ù', '&ugrave;' => 'ù', '&uml;' => '¨', '&upsih;' => 'ϒ', '&Upsilon;' => 'Υ', '&upsilon;' => 'υ', '&Uuml;' => 'Ü', '&uuml;' => 'ü', '&weierp;' => '℘', '&Xi;' => 'Ξ', '&xi;' => 'ξ', '&Yacute;' => 'Ý', '&yacute;' => 'ý', '&yen;' => '¥', '&Yuml;' => 'Ÿ', '&yuml;' => 'ÿ', '&Zeta;' => 'Ζ', '&zeta;' => 'ζ', '&zwj;' => '‍', '&zwnj;' => '‌');

    public function decodeEntities($text)
    {
        $text= $this->htmlentities2utf8($text);
        #$text = preg_replace_callback('/&#(\d+);/me', array($this, 'uniChrDecimal'), $text);
        #$text = preg_replace_callback('/&#x([a-f0-9]+);/mei', array($this, 'uniChrHex'), $text);
        $text = strtr($text, $this->_htmlEntities);
        return $text;
    }

    /**
     * because of the html_entity_decode() bug with UTF-8
     *
     * @param $string
     * @return mixed
     */
    public function htmlentities2utf8 ($string)
    {
        $string = preg_replace_callback('~&(#(x?))?([^;]+);~', 'html_entity_replace', $string);
        return $string;
    }

    /**
     * Return unicode char by its code
     *
     * @param int $u
     * @return char
     */
    public function uniChrDecimal($u)
    {
        return mb_convert_encoding('&#' . intval($u) . ';', 'UTF-8', 'HTML-ENTITIES');
    }

    /**
     * Return unicode char by its code
     *
     * @param int $u
     * @return char
     */
    public function uniChrHex($u)
    {
        return mb_convert_encoding('0x' . intval($u) . ';', 'UTF-8', 'HTML-ENTITIES');
    }

}