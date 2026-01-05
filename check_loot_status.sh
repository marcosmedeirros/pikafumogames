#!/bin/bash
# Script para verificar status do sistema de loot boxes

echo "🎁 === VERIFICAÇÃO DO SISTEMA DE LOOT BOXES ===="
echo ""

# Verificar diretório
echo "📁 Diretório logs:"
if [ -d "logs" ]; then
    echo "   ✅ Existe"
    echo "   📝 Conteúdo:"
    ls -lah logs/
else
    echo "   ⚠️  Não existe (será criado automaticamente)"
fi

echo ""
echo "📄 Arquivos criados:"
ls -lah test_loot*.php setup_logging.php DEBUG_GUIDE.md 2>/dev/null | grep -E "test_loot|setup_logging|DEBUG"

echo ""
echo "🔧 Próximos passos:"
echo "   1. Acesse: http://seusite.com/test_loot_debug.php"
echo "   2. Verifique o status do banco de dados"
echo "   3. Clique em 'Testar Caixa Bolicheiro'"
echo "   4. Verifique os logs em /logs/loot_boxes.log"
echo "   5. Abra DevTools (F12) e veja o console"

echo ""
echo "✅ Sistema preparado para debug!"
