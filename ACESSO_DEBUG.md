# 🎁 Instruções de Acesso - Sistema de Loot Boxes

## ✅ URLs Corrigidas

### **Opção 1: Debug Avatar (Recomendado para começar)**
```
https://pikafumogames.tech/debug_avatar.php
```
- ✅ Não requer login
- ✅ Funciona diretamente
- ✅ Mostra status completo
- ✅ Permite testes de caixas
- ✅ Logging detalhado

---

### **Opção 2: Teste das Loot Boxes (na raiz)**
```
https://pikafumogames.tech/test_loot_debug.php
```
- Versão completa para debug
- Pode requerer ajustes de caminho

---

### **Opção 3: Teste na pasta /games/**
```
https://pikafumogames.tech/games/test_loot.php
```
- Acesso dentro da pasta games
- Requer login automático

---

### **Opção 4: Avatar Personalizado (com login)**
```
https://pikafumogames.tech/games/avatar.php
```
- Página completa de avatar
- Requer estar autenticado
- Melhor experiência de usuário

---

## 🚀 Como Usar o Debug Avatar

1. **Abra a página:**
   ```
   https://pikafumogames.tech/debug_avatar.php
   ```

2. **Verifique o status:**
   - User ID
   - Nome do usuário
   - Saldo de pontos
   - Quantidade de itens no inventário

3. **Teste uma caixa:**
   - Clique em "Testar Bolicheiro", "Testar Pnip" ou "Testar PDSA"
   - Aguarde a resposta

4. **Verifique o console do navegador (F12):**
   - Abra DevTools com F12
   - Vá para a aba "Console"
   - Veja os logs em tempo real

5. **Verifique os logs do servidor:**
   - Arquivo: `/logs/debug_avatar.log`
   - Arquivo: `/logs/loot_boxes.log`

---

## 📊 O que você verá

### Console (F12):
```
🎁 Debug Avatar carregado
Componentes: Object { colors: {...}, hardware: {...}, ... }
Caixas: Object { basica: {...}, top: {...}, premium: {...} }
Avatar atual: Object { color: "default", hardware: "none", ... }

🎁 Testando caixa: basica
📡 Enviando: {api: 'abrir_caixa', tipo_caixa: 'basica'}
✅ HTTP: 200 OK
📄 Resposta bruta: {"sucesso":true,"categoria":"colors","item_id":"neon_blue",...}
🔄 Dados: Object { sucesso: true, categoria: "colors", ... }
```

### Resultado na página:
```
✅ Sucesso!

{
  "sucesso": true,
  "mensagem": "Item obtido!",
  "categoria": "colors",
  "item_id": "neon_blue",
  "item_nome": "Azul Neon",
  "raridade": "common",
  "pontos_restantes": 9969
}
```

---

## 🐛 Se Algo Não Funcionar

### 1️⃣ Debug Avatar não carrega (branco/erro)
- Verifique `/logs/debug_avatar.log`
- Confirme que `core/conexao.php` está funcionando
- Verifique conexão ao banco de dados

### 2️⃣ Botões não fazem nada
- Abra F12 → Console
- Clique em um botão
- Procure por erros em vermelho

### 3️⃣ Erro JSON
- Console mostrará: "❌ Erro ao parsear JSON"
- Verifique se há erros do PHP (output antes do JSON)
- Veja o arquivo `/logs/debug_avatar.log`

### 4️⃣ Saldo não muda
- Verifique se pontos estão sendo debitados em `usuarios`
- Verifique se item está sendo inserido em `usuario_inventario`
- Verifique logs do banco de dados

---

## 📋 Arquivos de Log

### `/logs/debug_avatar.log`
Logs detalhados do `debug_avatar.php`:
```
=== DEBUG AVATAR INICIADO ===
Session status: 2
POST data: {}
Includes carregados
User ID: 1
Usuário encontrado: {"nome":"Marcos","pontos":500}
```

### `/logs/loot_boxes.log`
Logs da função `abrirLootBox()`:
```
[2026-01-05 16:30:45] User: 1 | Caixa: basica
  Saldo: 500 pts | Custo: 30 pts
  Raridade sorteada: common
  Item escolhido: colors/neon_blue (Azul Neon)
  ✅ Sucesso! Pontos restantes: 470
```

---

## 🎯 Checklist de Funcionamento

- [ ] `debug_avatar.php` abre sem erros
- [ ] Saldo de pontos aparece correto
- [ ] Botão "Testar Bolicheiro" é clicável
- [ ] Console (F12) mostra "🎁 Debug Avatar carregado"
- [ ] Após clicar, aparece JSON com resultado
- [ ] Pontos diminuem ou mensagem de erro aparece
- [ ] Arquivo `/logs/debug_avatar.log` é criado e tem conteúdo
- [ ] Arquivo `/logs/loot_boxes.log` tem registros

---

## 📞 Próximos Passos

1. **Teste**: Acesse `https://pikafumogames.tech/debug_avatar.php`
2. **Abra F12** e vá para Console
3. **Clique em um botão** de teste
4. **Compartilhe** o que vê no console comigo

Se tudo funcionar:
- Avatar real em `/games/avatar.php` deve funcionar
- Loot boxes devem abrir corretamente

---

## 🔗 Resumo de URLs

| Função | URL | Login | Recomendado |
|--------|-----|-------|-------------|
| Debug básico | `/debug_avatar.php` | ❌ Não | ✅ Sim |
| Debug completo (raiz) | `/test_loot_debug.php` | ❌ Não | ⚠️ Talvez |
| Debug (games) | `/games/test_loot.php` | ⚠️ Talvez | ⚠️ Talvez |
| Avatar real | `/games/avatar.php` | ✅ Sim | ✅ Sim (depois de testado) |

