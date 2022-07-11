<?php

namespace Valet;

class CliPrompt
{
    /**
     * Prompts the user for input and shows what they type.
     *
     * @param $question
     * @param bool $hidden
     * @param null $suggestion
     * @param null $default
     *
     * @return string
     */
    public function prompt($question, $hidden = false, $suggestion = null, $default = null)
    {
        $question = $suggestion ? "$question [$suggestion]" : $question;
        $question = $default ? "$question ($default)" : $question;
        $question .= PHP_EOL;

        print_r($question);

        if ($hidden) {
            system('stty -echo');
        }
        $answer = self::trimAnswer(fgets(STDIN, 4096));
        if ($hidden) {
            system('stty echo');
        }

        return !empty($answer) ? $answer : $default;
    }

    private static function trimAnswer($str)
    {
        return preg_replace('{\r?\n$}D', '', $str);
    }
}
