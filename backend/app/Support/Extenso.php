<?php

namespace App\Support;

/** Valores por extenso em português (meticais e centavos). */
final class Extenso
{
    private const U = ['zero','um','dois','três','quatro','cinco','seis','sete','oito','nove','dez','onze','doze','treze','catorze','quinze','dezasseis','dezassete','dezoito','dezanove'];
    private const D = [2=>'vinte','trinta','quarenta','cinquenta','sessenta','setenta','oitenta','noventa'];
    private const C = [1=>'cento','duzentos','trezentos','quatrocentos','quinhentos','seiscentos','setecentos','oitocentos','novecentos'];

    private static function ate999(int $n): string
    {
        if ($n === 100) return 'cem';
        $p = [];
        if ($n >= 100) { $p[] = self::C[intdiv($n, 100)]; $n %= 100; }
        if ($n >= 20) { $p[] = self::D[intdiv($n, 10)]; $n %= 10; if ($n) $p[] = self::U[$n]; }
        elseif ($n > 0) $p[] = self::U[$n];
        return implode(' e ', $p);
    }

    public static function inteiro(int $n): string
    {
        if ($n === 0) return 'zero';
        $grupos = [];
        $esc = [['', ''], ['mil', 'mil'], ['milhão', 'milhões'], ['mil milhões', 'mil milhões']];
        $i = 0; $partes = [];
        while ($n > 0 && $i < 4) { $grupos[$i] = $n % 1000; $n = intdiv($n, 1000); $i++; }
        for ($k = count($grupos) - 1; $k >= 0; $k--) {
            $g = $grupos[$k]; if ($g === 0) continue;
            if ($k === 1 && $g === 1) $txt = 'mil';
            else { $txt = self::ate999($g); if ($k > 0) $txt .= ' ' . ($g === 1 ? $esc[$k][0] : $esc[$k][1]); }
            $partes[] = ['t' => $txt, 'g' => $g, 'k' => $k];
        }
        $out = '';
        foreach ($partes as $j => $p) {
            if ($j > 0) {
                // «e» antes do último grupo quando é < 100 ou centena redonda
                $last = $j === count($partes) - 1;
                $out .= ($last && $p['k'] === 0 && ($p['g'] < 100 || $p['g'] % 100 === 0)) ? ' e ' : ' ';
            }
            $out .= $p['t'];
        }
        return $out;
    }

    public static function meticais(float $v): string
    {
        $v = round(max(0, $v), 2);
        $int = (int)floor($v); $cent = (int)round(($v - $int) * 100);
        $s = self::inteiro($int) . ($int === 1 ? ' metical' : ' meticais');
        if (preg_match('/(milhão|milhões)$/', self::inteiro($int))) $s = self::inteiro($int) . ' de meticais';
        if ($cent > 0) $s .= ' e ' . self::inteiro($cent) . ($cent === 1 ? ' centavo' : ' centavos');
        return $s;
    }
}
