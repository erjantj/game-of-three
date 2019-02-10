<?php

namespace App\Game;

class Player
{
    public $username;
    private $numberMin;
    private $numberMax;
    private $divisor;
    private $moves;

    public function __construct()
    {
        $config = config('game');
        $this->numberMin = $config['number_min'];
        $this->numberMax = $config['number_max'];
        $this->divisor = 3;
        $this->moves = [1, -1];

        if ($this->numberMin < $this->divisor) {
            throw new \Exception("Number min cannot be less than divisor {$this->divisor}");
        }

        if ($this->numberMax < $this->divisor) {
            throw new \Exception("Number max cannot be less than divisor {$this->divisor}");
        }
    }

    public function generateRandomNumber(): int
    {
        $randomNumber = rand($this->numberMin, $this->numberMax);
        echo "🤔 {$randomNumber}\n";

        return $randomNumber;
    }

    public function move(int $number): int
    {
        echo "⭕️ {$number}\n";

        $move = 0;
        $sign = '+';
        if ($number % $this->divisor) {
            if (!(($number + 1) % $this->divisor)) {
                $move = 1;
            } else {
                $move = -1;
                $sign = '-';
            }
        }

        $nextNumber = ($number + $move) / $this->divisor;

        echo "✅ ({$number} {$sign} " . abs($move) . ") / {$this->divisor} = {$nextNumber}\n";

        return $nextNumber;
    }

    public function setUsername(string $username)
    {
        $this->username = $username;
        echo "\n👋 Hi! My name is $username\n\n";
    }

    public function win()
    {
        echo "\n🎉 I win!\n";
    }

    public function loose()
    {
        echo "\n😢 I lost \n";
    }
}
