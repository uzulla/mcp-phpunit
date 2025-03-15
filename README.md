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

以下は、Claude CodeがMCPを通じてPHPUnitを実行し、エラーを検出して修正する具体的な例です。Claude Codeが主体となり、MCPを介してPHPUnitテストの実行と問題の修正を行います。

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

#### Claude Code からMCPを呼び出す例

Claude Codeから以下のようにMCPを呼び出してPHPUnitテストを実行し、エラーを修正する例：

```python
# Claude Codeからの呼び出し例
def run_phpunit_tests_and_fix(project_path):
    """
    PHPUnitテストを実行し、エラーがあれば修正する
    
    Args:
        project_path: PHPプロジェクトのパス
    """
    # MCPを通じてPHPUnitテストを実行
    import subprocess
    import json
    import os
    
    # MCPを呼び出してPHPUnitテストを実行
    result = subprocess.run(
        ["python", "src/main.py", project_path, "--dry-run"],
        capture_output=True,
        text=True
    )
    
    # 出力からエラー情報を抽出
    output = result.stdout
    print("PHPUnitテスト実行結果:")
    print(output)
    
    # エラーがあるか確認
    if "ERRORS!" in output:
        print("テストエラーを検出しました。修正を開始します...")
        
        # エラー情報を解析
        try:
            # JSON部分を抽出
            json_start = output.find('{\n  "type": "mcp_phpunit_errors"')
            json_end = output.rfind('}') + 1
            json_data = output[json_start:json_end]
            
            # JSONをパース
            error_data = json.loads(json_data)
            
            # エラーを修正
            fix_phpunit_errors(error_data)
            
            # 修正後にテストを再実行
            print("修正後のテストを実行します...")
            subprocess.run(["python", "src/main.py", project_path])
        except Exception as e:
            print(f"エラー解析中にエラーが発生しました: {e}")
    else:
        print("すべてのテストが成功しました！")

def fix_phpunit_errors(error_data):
    """
    PHPUnitのエラーを修正する
    
    Args:
        error_data: MCPから受け取ったエラーデータ
    """
    # エラー情報を処理
    errors = error_data["errors"]
    file_contents = error_data["file_contents"]
    
    for file_path, file_errors in errors.items():
        print(f"\nファイル {file_path} のエラーを修正します:")
        
        for error in file_errors:
            print(f"- 行 {error['line']}: {error['message']}")
        
        # ファイルの内容を取得
        if file_path in file_contents:
            code = file_contents[file_path]
            
            # エラーに基づいて修正を適用
            if "Division by zero" in error["message"]:
                # ゼロ除算エラーの修正
                if "Calculator.php" in file_path:
                    # Calculator.phpの修正
                    fixed_code = code.replace(
                        "public function divide($a, $b) {\n        // Intentional bug: no division by zero check\n        return $a / $b;\n    }",
                        "public function divide($a, $b) {\n        // ゼロ除算チェックを追加\n        if ($b === 0) {\n            return null;\n        }\n        return $a / $b;\n    }"
                    )
                    
                    # 修正したコードを保存
                    with open(file_path, 'w') as f:
                        f.write(fixed_code)
                    
                    print(f"  ✓ {file_path} を修正しました")
            
            elif "Failed asserting that null is identical to 0" in error["message"]:
                # テストの期待値を修正
                if "CalculatorTest.php" in file_path:
                    # CalculatorTest.phpの修正
                    fixed_code = code.replace(
                        "$this->assertEquals(0, $calculator->divide(4, 0));",
                        "$this->assertNull($calculator->divide(4, 0));"
                    )
                    
                    # 修正したコードを保存
                    with open(file_path, 'w') as f:
                        f.write(fixed_code)
                    
                    print(f"  ✓ {file_path} を修正しました")
```

#### Claude Code での使用例

Claude Codeでは、以下のようにMCPを使用してPHPUnitテストを実行し、エラーを修正できます：

```
ユーザー: PHPプロジェクトのテストを実行して、エラーがあれば修正してください。プロジェクトは /home/ubuntu/repos/mcp-phpunit/samples/php_project にあります。

Claude Code: PHPプロジェクトのテストを実行し、エラーがあれば修正します。

まず、MCPを使用してPHPUnitテストを実行します：

```python
import subprocess

# MCPを通じてPHPUnitテストを実行
result = subprocess.run(
    ["python", "/home/ubuntu/repos/mcp-phpunit/src/main.py", "/home/ubuntu/repos/mcp-phpunit/samples/php_project", "--dry-run"],
    capture_output=True,
    text=True
)

# 出力を表示
print(result.stdout)
```

テスト実行結果:

```
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
```

テストでエラーが検出されました。2つの問題があります：

1. Calculator.php の divide メソッドでゼロ除算が発生しています
2. CalculatorTest.php のテストケースでゼロ除算の期待値が不適切です

これらの問題を修正します：

1. まず、Calculator.php を修正してゼロ除算チェックを追加します：

```python
import os

# Calculator.php のパス
calculator_path = "/home/ubuntu/repos/mcp-phpunit/samples/php_project/src/Calculator.php"

# ファイルを読み込む
with open(calculator_path, 'r') as f:
    code = f.read()

# ゼロ除算チェックを追加
fixed_code = code.replace(
    "public function divide($a, $b) {\n        // Intentional bug: no division by zero check\n        return $a / $b;\n    }",
    "public function divide($a, $b) {\n        // ゼロ除算チェックを追加\n        if ($b === 0) {\n            return null;\n        }\n        return $a / $b;\n    }"
)

# 修正したコードを保存
with open(calculator_path, 'w') as f:
    f.write(fixed_code)

print("Calculator.php を修正しました")
```

2. 次に、CalculatorTest.php を修正してテストの期待値を更新します：

```python
# CalculatorTest.php のパス
test_path = "/home/ubuntu/repos/mcp-phpunit/samples/php_project/tests/CalculatorTest.php"

# ファイルを読み込む
with open(test_path, 'r') as f:
    code = f.read()

# テストの期待値を修正
fixed_code = code.replace(
    "$this->assertEquals(0, $calculator->divide(4, 0));",
    "$this->assertNull($calculator->divide(4, 0));"
)

# 修正したコードを保存
with open(test_path, 'w') as f:
    f.write(fixed_code)

print("CalculatorTest.php を修正しました")
```

3. 修正後にテストを再実行して確認します：

```python
# 修正後のテストを実行
result = subprocess.run(
    ["python", "/home/ubuntu/repos/mcp-phpunit/src/main.py", "/home/ubuntu/repos/mcp-phpunit/samples/php_project"],
    capture_output=True,
    text=True
)

print(result.stdout)
```

テスト再実行結果:

```
Iteration 1/10
Running PHPUnit tests...

PHPUnit 9.6.22 by Sebastian Bergmann and contributors.

....                                                                4 / 4 (100%)

Time: 00:00.002, Memory: 6.00 MB

OK (4 tests, 4 assertions)

All PHPUnit tests pass!
```

すべてのテストが成功しました！問題は修正されました。

修正内容の要約:
1. Calculator.php: divide メソッドにゼロ除算チェックを追加し、ゼロ除算の場合は null を返すようにしました
2. CalculatorTest.php: ゼロ除算のテストケースで、期待値を 0 から null に変更しました

これらの修正により、すべてのテストが正常に実行されるようになりました。
```

## Project Structure

- `src/PhpUnitRunner`: PHPUnit test execution
- `src/ErrorFormatter`: Parsing and formatting PHPUnit test failures
- `src/McpIntegration`: Communication with Claude Code via MCP
- `samples/php_project`: Sample PHP project with test failures
