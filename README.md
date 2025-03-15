# MCP PHPUnit Integration

This project provides integration between PHPUnit and Claude Code via MCP (Multi-agent Conversation Protocol). It allows you to run PHPUnit tests, detect failures, and report them to Claude Code for assistance with fixing the issues.

## Features

- Run PHPUnit tests and capture failures
- Format test failures for Claude Code
- Send formatted errors to Claude Code via MCP
- Process errors incrementally in batches
- Sample PHP project with intentional test failures

## Usage

```bash
python src/main.py /path/to/php/project [options]
```

### Options

- `--verbose, -v`: Run in verbose mode
- `--max-errors, -m`: Maximum errors per batch (default: 3)
- `--max-iterations, -i`: Maximum iterations to run (default: 10)
- `--dry-run`: Run without sending to Claude (for testing)

## Integration with Claude Code

### Using with Claude Code in MCP

以下は、MCPを使用してPHPUnitのエラーをClaude Codeに送信し、修正提案を受け取る具体的な例です。

#### 実行例

1. **PHPUnitテストの実行とエラー検出**:

```bash
$ python src/main.py samples/php_project

Iteration 1/10
Running PHPUnit tests...

PHPUnit 9.6.22 by Sebastian Bergmann and contributors.

F                                                                   1 / 4 (25%)
E                                                                   2 / 4 (50%)
..                                                                  4 / 4 (100%)

Time: 00:00.002, Memory: 6.00 MB

There was 1 error:

1) Tests\CalculatorTest::testDivide
Division by zero

/home/ubuntu/repos/mcp-phpunit/samples/php_project/src/Calculator.php:17

There was 1 failure:

1) Tests\CalculatorTest::testDivide
Failed asserting that null is identical to 0.

/home/ubuntu/repos/mcp-phpunit/samples/php_project/tests/CalculatorTest.php:22

ERRORS!
Tests: 4, Assertions: 4, Errors: 1, Failures: 1.

Found 1 batches of test failures

Processing batch 1/1
Sending message to Claude Code via MCP:
{
  "type": "mcp_phpunit_errors",
  "batch_info": {
    "index": 0,
    "total_errors": 2,
    "batch_size": 2,
    "has_more": false
  },
  "errors": {
    "/home/ubuntu/repos/mcp-phpunit/samples/php_project/src/Calculator.php": [
      {
        "message": "Division by zero",
        "file": "/home/ubuntu/repos/mcp-phpunit/samples/php_project/src/Calculator.php",
        "line": 17,
        "test_name": "testDivide",
        "error_type": "Error",
        "class_name": "Tests\\CalculatorTest"
      }
    ],
    "/home/ubuntu/repos/mcp-phpunit/samples/php_project/tests/CalculatorTest.php": [
      {
        "message": "Failed asserting that null is identical to 0.",
        "file": "/home/ubuntu/repos/mcp-phpunit/samples/php_project/tests/CalculatorTest.php",
        "line": 22,
        "test_name": "testDivide",
        "error_type": "PHPUnit\\Framework\\ExpectationFailedException",
        "class_name": "Tests\\CalculatorTest"
      }
    ]
  },
  "file_contents": {
    "/home/ubuntu/repos/mcp-phpunit/samples/php_project/src/Calculator.php": "<?php\n\nnamespace App;\n\nclass Calculator {\n    public function add($a, $b) {\n        return $a + $b;\n    }\n    \n    public function subtract($a, $b) {\n        return $a - $b;\n    }\n    \n    public function multiply($a, $b) {\n        return $a * $b;\n    }\n    \n    public function divide($a, $b) {\n        // Intentional bug: no division by zero check\n        return $a / $b;\n    }\n}\n",
    "/home/ubuntu/repos/mcp-phpunit/samples/php_project/tests/CalculatorTest.php": "<?php\n\nnamespace Tests;\n\nuse App\\Calculator;\nuse PHPUnit\\Framework\\TestCase;\n\nclass CalculatorTest extends TestCase {\n    public function testAdd() {\n        $calculator = new Calculator();\n        $this->assertEquals(4, $calculator->add(2, 2));\n    }\n    \n    public function testSubtract() {\n        $calculator = new Calculator();\n        $this->assertEquals(0, $calculator->subtract(2, 2));\n    }\n    \n    public function testMultiply() {\n        $calculator = new Calculator();\n        $this->assertEquals(6, $calculator->multiply(2, 3));\n    }\n    \n    public function testDivide() {\n        $calculator = new Calculator();\n        $this->assertEquals(2, $calculator->divide(4, 2));\n        // This will fail - division by zero\n        $this->assertEquals(0, $calculator->divide(4, 0));\n    }\n}\n"
  },
  "project_path": "/home/ubuntu/repos/mcp-phpunit/samples/php_project"
}
```

2. **Claude Codeからの応答例**:

```
Claude Code からの応答:

PHPUnitテストの失敗を分析しました。問題は2つあります：

1. Calculator.php の divide メソッドでゼロ除算が発生しています
2. CalculatorTest.php のテストケースでゼロ除算の期待値が不適切です

以下の修正を提案します：

## Calculator.php の修正

```php
public function divide($a, $b) {
    // ゼロ除算チェックを追加
    if ($b === 0) {
        return null; // または例外をスローする選択肢もあります
    }
    return $a / $b;
}
```

## CalculatorTest.php の修正

テストケースには2つの選択肢があります：

1. ゼロ除算の場合に null を期待する:
```php
public function testDivide() {
    $calculator = new Calculator();
    $this->assertEquals(2, $calculator->divide(4, 2));
    // ゼロ除算の場合は null を期待
    $this->assertNull($calculator->divide(4, 0));
}
```

2. 例外をスローする実装に変更する場合:
```php
public function testDivide() {
    $calculator = new Calculator();
    $this->assertEquals(2, $calculator->divide(4, 2));
    
    // 例外をテスト
    $this->expectException(\InvalidArgumentException::class);
    $calculator->divide(4, 0);
}
```

どちらのアプローチを選ぶかはコードの設計方針によります。ゼロ除算は通常、例外をスローするのがベストプラクティスですが、特定のユースケースでは null や特別な値を返す場合もあります。
```

3. **修正の適用**:

Claude Codeの提案に基づいて、以下のように修正を適用します：

```php
// Calculator.php の修正
public function divide($a, $b) {
    // ゼロ除算チェックを追加
    if ($b === 0) {
        return null;
    }
    return $a / $b;
}

// CalculatorTest.php の修正
public function testDivide() {
    $calculator = new Calculator();
    $this->assertEquals(2, $calculator->divide(4, 2));
    // ゼロ除算の場合は null を期待
    $this->assertNull($calculator->divide(4, 0));
}
```

4. **修正後のテスト実行**:

```bash
$ python src/main.py samples/php_project

Iteration 1/10
Running PHPUnit tests...

PHPUnit 9.6.22 by Sebastian Bergmann and contributors.

....                                                                4 / 4 (100%)

Time: 00:00.002, Memory: 6.00 MB

OK (4 tests, 4 assertions)

All PHPUnit tests pass!
```

#### MCP クライアントでの実装例

MCPクライアントでこのメッセージタイプを処理する例：

```python
def handle_mcp_message(message):
    if message["type"] == "mcp_phpunit_errors":
        # エラー情報の抽出
        errors = message["errors"]
        file_contents = message["file_contents"]
        
        # Claude Code へのプロンプト作成
        prompt = "PHPUnitテストで以下のエラーが発生しました。修正方法を提案してください：\n\n"
        
        # ファイルごとのエラーを処理
        for file_path, file_errors in errors.items():
            prompt += f"## ファイル: {file_path}\n"
            
            for error in file_errors:
                prompt += f"- エラー (行 {error['line']}): {error['message']}\n"
            
            # ファイルの内容を追加
            if file_path in file_contents:
                prompt += f"\n```php\n{file_contents[file_path]}\n```\n\n"
        
        # Claude Code に送信
        claude_response = send_to_claude(prompt)
        
        # レスポンスを表示
        print("Claude Code からの修正提案:")
        print(claude_response)
        
        # ユーザーに修正を適用するか確認
        apply_fixes = input("Claude Code の提案を適用しますか？ (y/n): ")
        if apply_fixes.lower() == 'y':
            # 修正を適用するロジック
            # ...
            print("修正を適用しました。テストを再実行します...")
```

#### Claude Code へのプロンプト例

```
PHPUnitテストで以下のエラーが発生しました。修正方法を提案してください：

## ファイル: /home/ubuntu/repos/mcp-phpunit/samples/php_project/src/Calculator.php
- エラー (行 17): Division by zero

```php
<?php

namespace App;

class Calculator {
    public function add($a, $b) {
        return $a + $b;
    }
    
    public function subtract($a, $b) {
        return $a - $b;
    }
    
    public function multiply($a, $b) {
        return $a * $b;
    }
    
    public function divide($a, $b) {
        // Intentional bug: no division by zero check
        return $a / $b;
    }
}
```

## ファイル: /home/ubuntu/repos/mcp-phpunit/samples/php_project/tests/CalculatorTest.php
- エラー (行 22): Failed asserting that null is identical to 0.

```php
<?php

namespace Tests;

use App\Calculator;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase {
    public function testAdd() {
        $calculator = new Calculator();
        $this->assertEquals(4, $calculator->add(2, 2));
    }
    
    public function testSubtract() {
        $calculator = new Calculator();
        $this->assertEquals(0, $calculator->subtract(2, 2));
    }
    
    public function testMultiply() {
        $calculator = new Calculator();
        $this->assertEquals(6, $calculator->multiply(2, 3));
    }
    
    public function testDivide() {
        $calculator = new Calculator();
        $this->assertEquals(2, $calculator->divide(4, 2));
        // This will fail - division by zero
        $this->assertEquals(0, $calculator->divide(4, 0));
    }
}
```
```

## Project Structure

- `src/PhpUnitRunner`: PHPUnit test execution
- `src/ErrorFormatter`: Parsing and formatting PHPUnit test failures
- `src/McpIntegration`: Communication with Claude Code via MCP
- `samples/php_project`: Sample PHP project with test failures
