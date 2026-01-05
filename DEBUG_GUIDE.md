# 🎁 Guia de Debug - Sistema de Loot Boxes

## O que foi ajustado

### ❌ Problemas Identificados

1. **Logging não funcionava**: A função `abrirLootBox()` não registrava os erros de forma confiável
2. **Console não mostrava debug info**: A função JavaScript `openCaseModal()` não tinha logs suficientes
3. **Resposta JSON não era tratada corretamente**: Parsing de JSON podia falhar silenciosamente

---

## 🔧 Soluções Implementadas

### 1. **Logging Robusto em `core/avatar.php`**

```php
// Agora cria o diretório logs automaticamente
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

// Registra cada passo da execução
@file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);
```

**Arquivo de log**: `/logs/loot_boxes.log`

Cada abertura de caixa registra:
- Timestamp
- User ID
- Tipo de caixa
- Saldo do usuário
- Custo da caixa
- Raridade sorteada
- Item escolhido
- Status final (sucesso/erro)

---

### 2. **Console Detalhado em `games/avatar.php`**

Adicionados `console.log()` em pontos críticos:

```javascript
console.log('🎁 Abrindo caixa:', tierKey);
console.log('📦 Itens possíveis:', allPossibleItems.length);
console.log('🎲 Pool de sorteio:', lotteryPool.length);
console.log('📡 Enviando requisição para servidor...');
console.log('✅ Resposta HTTP:', resp.status);
console.log('📄 Resposta bruta:', text);
console.log('✅ Caixa aberta com sucesso!');
```

---

### 3. **Tratamento de Erros Melhorado**

```javascript
// Agora faz parsing seguro
let data;
try {
    data = JSON.parse(text);
} catch(e) {
    console.error('❌ Erro ao parsear JSON:', e, 'Texto:', text);
    alert('Erro ao processar resposta do servidor');
    return;
}
```

---

## 📊 Como Debugar

### Opção 1: Via Browser

Acesse: `http://seusite.com/test_loot_debug.php`

**Mostra:**
- Status do usuário
- Contagem de registros no BD
- Avatar atual
- Inventário
- Botões para testar caixas manualmente

---

### Opção 2: Verificar Logs

**Logs de loot boxes:**
```
/logs/loot_boxes.log
```

Exemplo de log bem-sucedido:
```
[2026-01-05 14:30:45] User: 1 | Caixa: basica
  Saldo: 500 pts | Custo: 30 pts
  Raridade sorteada: common
  Item escolhido: colors/neon_blue (Azul Neon)
  ✅ Sucesso! Pontos restantes: 470
```

---

### Opção 3: Console do Navegador

1. Abra `games/avatar.php`
2. Pressione `F12` para abrir DevTools
3. Vá para a aba "Console"
4. Clique em um botão de caixa
5. Veja todos os logs em tempo real

---

## 🐛 Se Ainda Não Funcionar

### Passo 1: Verifique o Banco de Dados

```php
<?php
require 'core/conexao.php';

// Verificar se as tabelas existem
$tables = ['usuarios', 'usuario_avatars', 'usuario_inventario'];
foreach ($tables as $table) {
    $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
    echo "Tabela $table: " . ($stmt->fetch() ? "OK" : "NÃO EXISTE") . "\n";
}

// Verificar permissões do usuário
$stmt = $pdo->query("SELECT USER()");
echo "Usuário BD: " . $stmt->fetch(PDO::FETCH_COLUMN) . "\n";
?>
```

### Passo 2: Verificar Logs PHP

Se não vir arquivo `/logs/loot_boxes.log`, significa que:
- O diretório não pode ser criado
- Permissões de arquivo insuficientes

**Solução:**
```bash
mkdir -p /Users/marcos/pikafumogames/logs
chmod 755 /Users/marcos/pikafumogames/logs
```

### Passo 3: Teste Isolado

Execute: `test_loot_debug.php` no navegador para isolar problemas

---

## 📋 Checklist de Verificação

- [ ] Arquivo `/logs/` foi criado?
- [ ] Arquivo `/logs/loot_boxes.log` tem conteúdo?
- [ ] Console do navegador (F12) mostra logs com 🎁 emoji?
- [ ] Resposta JSON é válida?
- [ ] Pontos foram debitados no banco de dados?
- [ ] Item foi inserido em `usuario_inventario`?

---

## 🎯 Próximos Passos

1. **Acesse** o arquivo `/test_loot_debug.php`
2. **Clique** em um botão de teste de caixa
3. **Verifique** o console (F12) para logs detalhados
4. **Compartilhe** a saída do log se ainda não funcionar

---

## 📞 Informações de Contato

Se precisar de mais ajuda:
1. Verifique `/logs/loot_boxes.log`
2. Abra DevTools (F12) e copie os logs do console
3. Execute `test_loot_debug.php` e envie a saída
