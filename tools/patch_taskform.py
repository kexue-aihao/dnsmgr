# -*- coding: utf-8 -*-
import re
path = r'E:\dnsmgr\app\view\awssync\taskform.html'
text = open(path, 'rb').read().decode('utf-8')
lines = [ln.rstrip() for ln in text.splitlines()]
out, prev_blank = [], False
for ln in lines:
    blank = (ln.strip() == '')
    if blank:
        if not prev_blank and out:
            out.append('')
        prev_blank = True
    else:
        out.append(ln)
        prev_blank = False
text = '\n'.join(out) + '\n'

old = '  {/if}\n\n  <form onsubmit'
new = '''  {/if}
  <div class="alert alert-info" id="tokenHint" style="display:none;">
    请先到 <a href="/awssync/set"><b>API 设置</b></a> 保存小助理 Token，否则无法读取 AWS 账号与实例。
  </div>
  <div class="alert alert-success" style="margin-bottom:15px;">
    <strong>按顺序操作：</strong>
    ① 选择域名并点「读取解析记录」 → ② 选择一条 A 记录 → ③ 读取账号、选账号、读取实例、选 EC2 → ④ 设置间隔后点「保存任务」
  </div>
  <form onsubmit'''
if old in text:
    text = text.replace(old, new, 1)
else:
    text = text.replace('  {/if}\n  <form onsubmit', new.replace('\n\n', '\n'), 1)

for a, b in [
    ('is-required>选择域名', 'is-required>① 选择域名'),
    ('is-required>选择解析记录', 'is-required>② 选择 A 记录'),
    ('is-required>AWS 账号', 'is-required>③ AWS 账号'),
    ('is-required>AWS 实例选择', 'is-required>③ AWS 实例'),
    ('is-required>同步间隔（分钟）', 'is-required>④ 同步间隔（分钟）'),
    ('value="保存"', 'value="保存任务"'),
]:
    text = text.replace(a, b)

pat = re.compile(
    r'    <div class="form-group">\s*\n\s*<label class="col-sm-3 control-label no-padding-right" is-required>AWS Account ID</label>.*?'
    r'<div class="form-group">\s*\n\s*<label class="col-sm-3 control-label no-padding-right" is-required>④ 同步间隔',
    re.S,
)
repl = '''    <input type="hidden" name="aws_account_id" v-model="set.aws_account_id">
    <input type="hidden" name="aws_region" v-model="set.aws_region">
    <input type="hidden" name="aws_instance_id" v-model="set.aws_instance_id">

    <div class="form-group">
        <label class="col-sm-3 control-label no-padding-right" is-required>④ 同步间隔'''
text, n = pat.subn(repl, text, 1)
print('aws fields replaced:', n)

if "preflight" not in text:
    text = text.replace(
        '        this.loadAwsAccounts(true);\n\n    },',
        '''        var that = this;
        $.post('/awssync/preflight', function(res){
            if(res && res.code == 0){
                if(!res.token_ok){ document.getElementById('tokenHint').style.display = 'block'; }
                if(!res.table_ok){ layer.alert('数据库表 aws_sync 未就绪，请在服务器执行：bash update.sh', {icon:0}); }
            }
        });
        this.loadAwsAccounts(true);

    },''',
    )

save_new = '''        save(){
            if(!this.set.did){ layer.msg('请先选择域名', {icon:2}); return; }
            if(!this.set.recordid || !this.set.rr){ layer.msg('请先读取解析记录并选择一条 A 记录', {icon:2}); return; }
            if(!this.set.recordinfo){ this.onRecordPick(); }
            if(!this.set.recordinfo){ layer.msg('解析记录信息不完整，请重新选择 A 记录', {icon:2}); return; }
            if(!this.set.aws_account_id || !this.set.aws_region || !this.set.aws_instance_id){
                layer.msg('请先选择 AWS 账号并读取、选择 EC2 实例', {icon:2}); return;
            }
            var ii = layer.load(2, {shade:[0.1,'#fff']});
            $.ajax({
                type: 'POST',
                url: '/awssync/task/' + this.action,
                data: {
                    id: this.set.id,
                    did: parseInt(this.set.did, 10) || this.set.did,
                    rr: this.set.rr,
                    recordid: this.set.recordid,
                    recordinfo: this.set.recordinfo,
                    aws_account_id: this.set.aws_account_id,
                    aws_region: this.set.aws_region,
                    aws_instance_id: this.set.aws_instance_id,
                    frequency: parseInt(this.set.frequency, 10) || 3,
                    remark: this.set.remark || ''
                },
                dataType: 'json',
                success: function(data) {
                    layer.close(ii);
                    if(data.code == 0){
                        layer.alert(data.msg, {icon:1}, function(){ window.location.href = '/awssync/task'; });
                    } else {
                        layer.alert(data.msg, {icon:2});
                    }
                },
                error: function(xhr){
                    layer.close(ii);
                    var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : ('保存失败（HTTP ' + (xhr.status || '') + '），请确认已执行 update.sh');
                    layer.alert(msg, {icon:2});
                }
            });
        }'''

text2, c = re.subn(r'\s*save\(\)\s*\{[\s\S]*?\n\s*\}\s*\n\s*\}\s*\n\}\);', save_new + '\n    }\n});', text, count=1)
print('save replaced:', c)
if c:
    text = text2

open(path, 'wb').write(text.encode('utf-8'))
print('ok', len(text))
