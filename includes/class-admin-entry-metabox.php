<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin meta boxes for a single registration entry: the manual/offline
 * entry form shown on "Add New", and the editable field view shown on
 * every existing entry (with Approve/Reject links).
 *
 * FRONTEND IS UNTOUCHED: This file only affects the backend
 * "Manual / Offline Registration Entry" meta box. The public shortcode
 * form (class-frontend-form.php / class-form-submission.php) continues
 * to use Reunion_Reg_Fields_Schema::get_fields() exactly as before.
 */
class Reunion_Reg_Admin_Entry_Metabox {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_entry_meta_box' ) );
        add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10, 3 );
        add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_edit_post_save' ), 10, 3 );
        add_filter( 'redirect_post_location', array( $this, 'filter_redirect_location' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ) );
        add_action( 'post_edit_form_tag', array( $this, 'ensure_multipart_enctype' ) );
    }

    public function ensure_multipart_enctype() {
        echo ' enctype="multipart/form-data"';
    }

    public function register_assets() {
        wp_register_script(
            'reunion-reg-admin-total-calc',
            REUNION_REG_PLUGIN_URL . 'assets/js/admin-total-calc.js',
            array(),
            REUNION_REG_VERSION,
            true
        );
    }

    public function add_entry_meta_box() {
        global $post;
        $is_new = ! $post || $post->post_status === 'auto-draft' || empty( get_post_meta( $post->ID, '_reunion_submitted_at', true ) );
        if ( $is_new ) {
            add_meta_box(
                'reunion_reg_manual_entry',
                'Manual / Offline Registration Entry',
                array( $this, 'render_manual_entry_meta_box' ),
                REUNION_REG_CPT_SLUG,
                'normal',
                'high'
            );
        } else {
            add_meta_box(
                'reunion_reg_details',
                'Registration Details',
                array( $this, 'render_entry_meta_box' ),
                REUNION_REG_CPT_SLUG,
                'normal',
                'high'
            );
        }
    }

    private function render_admin_total_calc_script( $guest_field_id, $donation_field_id, $total_field_id ) {
        $settings = Reunion_Reg_Fields_Schema::get_payment_settings();
        wp_enqueue_script( 'reunion-reg-admin-total-calc' );
        ?>
        <div
            class="reunion-admin-total-calc"
            data-guest-field="<?php echo esc_attr( $guest_field_id ); ?>"
            data-donation-field="<?php echo esc_attr( $donation_field_id ); ?>"
            data-total-field="<?php echo esc_attr( $total_field_id ); ?>"
            data-fee="<?php echo esc_attr( (float) $settings['registration_fee'] ); ?>"
            data-guest-fee="<?php echo esc_attr( (float) $settings['guest_fee'] ); ?>"
        ></div>
        <?php
    }

    private function render_manual_applicant_photo_field( $post_id = 0 ) {
        $photo_id  = $post_id ? (int) get_post_meta( $post_id, '_reunion_applicant_photo_id', true ) : 0;
        $photo_url = $post_id ? get_post_meta( $post_id, '_reunion_applicant_photo_url', true ) : '';
        if ( ! $photo_url && $photo_id ) { $photo_url = wp_get_attachment_url( $photo_id ) ?: ''; }
        ?>
        <tr class="reunion-manual-photo-row">
            <th style="width:180px;text-align:left;"><label for="reunion_manual_applicant_photo_file">আবেদনকারীর ছবি আপলোড করুন <span style="font-weight:400;color:#646970;">(ঐচ্ছিক)</span></label></th>
            <td>
                <?php if ( $photo_url ) : ?>
                    <div id="reunion_manual_photo_preview" style="margin-bottom:10px;">
                        <img src="<?php echo esc_url( $photo_url ); ?>" alt="Applicant photo" style="max-width:200px;height:auto;border:1px solid #dcdcde;border-radius:8px;display:block;">
                        <a href="<?php echo esc_url( $photo_url ); ?>" target="_blank" rel="noopener" style="font-size:12px;">Open full size</a>
                        &nbsp;·&nbsp;<a href="#" id="reunion_manual_photo_remove" style="font-size:12px;color:#b32d2e;">Remove</a>
                    </div>
                <?php else : ?>
                    <div id="reunion_manual_photo_preview" style="display:none;margin-bottom:10px;"></div>
                <?php endif; ?>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="file" name="reunion_manual_applicant_photo_file" id="reunion_manual_applicant_photo_file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" style="max-width:260px;">
                    <span id="reunion_manual_photo_filename" style="font-size:12px;color:#646970;">No file chosen</span>
                    <a href="#" id="reunion_manual_photo_clear" style="font-size:12px;color:#b32d2e;display:none;">Clear</a>
                    <span id="reunion_manual_photo_status" style="font-size:12px;color:#2271b1;display:none;"></span>
                </div>
                <p class="description" style="margin-top:6px;">JPG, PNG, WebP (সর্বোচ্চ ১০ MB) — ঐচ্ছিক। ৫-৬ MB ছবি স্বয়ংক্রিয়ভাবে ৩০০-৫০০ KB এ কম্প্রেস হবে।</p>
            </td>
        </tr>
        <?php
    }

    public function render_manual_entry_meta_box( $post ) {
        $fields = Reunion_Reg_Fields_Schema::get_fields();
        wp_nonce_field( 'reunion_reg_manual_entry_save', 'reunion_reg_manual_entry_nonce' );
        echo '<p style="color:#666;">Use this form to record a registration collected offline (cash, hand-to-hand bKash/Nagad, etc). It will be marked <strong>Approved</strong> automatically when published, a Registration ID will be generated, and a confirmation email will be sent.</p>';
        echo '<table class="form-table"><tbody>';
        // Offline Serial — standalone top-level, always visible and required regardless of payment method
        echo '<tr><th style="width:180px;text-align:left;"><label for="reunion_manual_offline_serial">অফলাইন সিরিয়াল নম্বর <span style="color:#d63638;">*</span></label></th><td>';
        echo '<input type="text" name="reunion_manual_offline_serial" id="reunion_manual_offline_serial" style="min-width:280px;" placeholder="অফলাইন রসিদ/বইয়ের সিরিয়াল নম্বর" required>';
        echo '<p class="description">ম্যানুয়াল রসিদ বইয়ের সিরিয়াল (প্রতিটি অফলাইন এন্ট্রির জন্য আবশ্যক)</p>';
        echo '</td></tr>';

        foreach ( $fields as $key => $field ) {
            if ( 'file' === $field['type'] && 'applicant_photo' !== $key ) { continue; }

            if ( 'applicant_photo' === $key ) {
                $this->render_manual_applicant_photo_field( $post ? $post->ID : 0 );
                continue;
            }

            // Backend-only: payment_channel includes Cash and custom provider/sender/serial/txid handling
            if ( 'payment_channel' === $key ) {
                $options = array( 'Cash', 'মোবাইল ব্যাংকিং', 'ব্যাংক একাউন্ট' );
                $default = 'Cash';
                echo '<tr><th style="width:180px;text-align:left;"><label>' . esc_html( $field['label'] ) . ' <span style="color:#d63638;">*</span></label></th><td>';
                foreach ( $options as $opt ) {
                    $checked = checked( $default, $opt, false );
                    echo '<label style="margin-right:16px;"><input type="radio" name="reunion_manual_payment_channel" value="' . esc_attr( $opt ) . '" ' . $checked . '> ' . esc_html( $opt ) . '</label>';
                }
                echo '</td></tr>';

                // Provider field (কোন মাধ্যমে দিয়েছেন?) — free text for bKash/Rocket/Nagad/Cash, shown only for Mobile Banking
                echo '<tr class="reunion-manual-conditional" data-depends-field="payment_channel" data-depends-value="মোবাইল ব্যাংকিং"><th style="width:180px;text-align:left;"><label for="reunion_manual_mobile_banking_method">কোন মাধ্যমে দিয়েছেন? <span style="color:#d63638;">*</span></label></th><td>';
                echo '<input type="text" name="reunion_manual_mobile_banking_method" id="reunion_manual_mobile_banking_method" style="min-width:280px;" placeholder="যেমন: bKash Personal, Rocket, Nagad, Cash">';
                echo '<p class="description">bKash / Rocket / Nagad লিখুন (যেমন: bKash Personal)</p>';
                echo '</td></tr>';

                // Sender mobile number — mandatory when Mobile Banking
                echo '<tr class="reunion-manual-conditional" data-depends-field="payment_channel" data-depends-value="মোবাইল ব্যাংকিং"><th style="width:180px;text-align:left;"><label for="reunion_manual_sender_mobile">প্রেরকের মোবাইল নম্বর <span style="color:#d63638;">*</span></label></th><td>';
                echo '<input type="text" name="reunion_manual_sender_mobile" id="reunion_manual_sender_mobile" style="min-width:280px;" placeholder="যে নাম্বার থেকে টাকা পাঠানো হয়েছে">';
                echo '</td></tr>';

                // Offline serial was already rendered as top-level field above the loop.

                // TxID — hidden when Cash (auto-generated CASH- serial on save), visible only for Mobile Banking / Bank Account
                echo '<tr class="reunion-manual-txid-row" style="display:none;" data-required-if-not-field="payment_channel" data-required-if-not-value="Cash"><th style="width:180px;text-align:left;"><label for="reunion_manual_tnx_id">ট্রানজেকশন আইডি (TxID) <span class="reunion-txid-required" style="color:#d63638;">*</span></label></th><td>';
                echo '<input type="text" name="reunion_manual_tnx_id" id="reunion_manual_tnx_id" style="min-width:280px;" placeholder="যেমন: BKA3X7R9Z2">';
                echo '<p class="description reunion-txid-help" style="display:none;">Cash হলে খালি রাখা যাবে — স্বয়ংক্রিয়ভাবে CASH- সিরিয়াল তৈরি হবে।</p>';
                echo '</td></tr>';
                continue;
            }

            // Skip keys already handled via custom payment block or not needed in manual view
            if ( in_array( $key, array( 'mobile_banking_method', 'sender_mobile', 'offline_serial', 'tnx_id', 'payment_receipt' ), true ) ) {
                continue;
            }

            if ( 'bank_name' === $key ) {
                echo '<tr class="reunion-manual-conditional" data-depends-field="payment_channel" data-depends-value="ব্যাংক একাউন্ট"><th style="width:180px;text-align:left;"><label for="reunion_manual_' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . ' <span style="color:#d63638;">*</span></label></th><td>';
                echo '<input type="text" name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" style="min-width:280px;" placeholder="' . esc_attr( $field['placeholder'] ?? '' ) . '">';
                echo '</td></tr>';
                continue;
            }

            echo '<tr';
            if ( ! empty( $field['depends_on'] ) ) {
                echo ' class="reunion-manual-conditional" data-depends-field="' . esc_attr( $field['depends_on']['field'] ) . '" data-depends-value="' . esc_attr( $field['depends_on']['value'] ) . '"';
            }
            echo '><th style="width:180px;text-align:left;"><label for="reunion_manual_' . esc_attr( $key ) . '">' . esc_html( $field['label'] );
            if ( ! empty( $field['required'] ) ) { echo ' <span style="color:#d63638;">*</span>'; }
            echo '</label></th><td>';
            if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
                echo '<select name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" style="min-width:220px;">';
                echo '<option value="">-- Select --</option>';
                foreach ( $field['options'] as $option ) {
                    $selected_attr = isset( $field['default'] ) ? selected( $field['default'], $option, false ) : '';
                    echo '<option value="' . esc_attr( $option ) . '" ' . $selected_attr . '>' . esc_html( $option ) . '</option>';
                }
                echo '</select>';
            } elseif ( in_array( $field['type'], array( 'number', 'computed' ), true ) ) {
                $default_val = isset( $field['default'] ) ? $field['default'] : 0;
                echo '<input type="number" name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" value="' . esc_attr( $default_val ) . '" style="min-width:140px;">';
            } else {
                echo '<input type="text" name="reunion_manual_' . esc_attr( $key ) . '" id="reunion_manual_' . esc_attr( $key ) . '" style="min-width:280px;" placeholder="' . esc_attr( $field['placeholder'] ?? '' ) . '">';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        $this->render_admin_total_calc_script( 'reunion_manual_guest_count', 'reunion_manual_donation', 'reunion_manual_total_amount' );
        ?>
        <script>
        (function(){
            function curValFor(field){
                var r=document.querySelector('input[name="reunion_manual_'+field+'"]:checked');
                if(r) return r.value;
                var el=document.getElementById('reunion_manual_'+field);
                if(el) return el.value||'';
                return '';
            }
            function applyConditionals(){
                document.querySelectorAll('.reunion-manual-conditional').forEach(function(row){
                    var cur=curValFor(row.getAttribute('data-depends-field'));
                    row.style.display=(cur===row.getAttribute('data-depends-value'))?'':'none';
                });
                var txRow=document.querySelector('.reunion-manual-txid-row');
                if(txRow){
                    var depF=txRow.getAttribute('data-required-if-not-field'), depV=txRow.getAttribute('data-required-if-not-value');
                    var cur=curValFor(depF);
                    var shouldRequire = cur !== depV;
                    var isCash = cur === 'Cash';
                    var showTx = !isCash;
                    txRow.style.display = showTx ? '' : 'none';
                    var star=txRow.querySelector('.reunion-txid-required');
                    if(star) star.style.display = shouldRequire ? '' : 'none';
                    var help=txRow.querySelector('.reunion-txid-help');
                    if(help) help.style.display = isCash ? '' : 'none';
                    var input=document.getElementById('reunion_manual_tnx_id');
                    if(input){
                        if(shouldRequire) { input.setAttribute('required','required'); input.disabled=false; }
                        else { input.removeAttribute('required'); input.removeAttribute('aria-invalid'); try{input.setCustomValidity('');}catch(e){} input.disabled=isCash; if(isCash) input.value=''; }
                    }
                }
            }
            document.querySelectorAll('input[name="reunion_manual_payment_channel"]').forEach(function(r){ r.addEventListener('change', applyConditionals); });
            applyConditionals();

            var fileInput=document.getElementById('reunion_manual_applicant_photo_file');
            var fileName=document.getElementById('reunion_manual_photo_filename');
            var clearLink=document.getElementById('reunion_manual_photo_clear');
            var statusEl=document.getElementById('reunion_manual_photo_status');
            var compressing=false;
            function setStatus(msg, color){ if(!statusEl) return; if(msg){ statusEl.textContent=msg; statusEl.style.color=color||'#2271b1'; statusEl.style.display=''; } else { statusEl.style.display='none'; statusEl.textContent=''; } }
            function formatKB(bytes){ return (bytes/1024).toFixed(1)+' KB'; }
            function fileToImage(file){
                return new Promise(function(resolve, reject){
                    var url=URL.createObjectURL(file);
                    var img=new Image();
                    var timer=setTimeout(function(){ URL.revokeObjectURL(url); reject(new Error('load timeout')); }, 8000);
                    img.onload=function(){ clearTimeout(timer); URL.revokeObjectURL(url); resolve(img); };
                    img.onerror=function(){ clearTimeout(timer); URL.revokeObjectURL(url); reject(new Error('decode failed')); };
                    img.src=url;
                });
            }
            function canvasToBlob(canvas, quality){
                return new Promise(function(resolve, reject){
                    if(canvas.toBlob) canvas.toBlob(function(b){ b?resolve(b):reject(new Error('toBlob null')); }, 'image/jpeg', quality);
                    else {
                        try{ var d=canvas.toDataURL('image/jpeg', quality).split(','); var s=atob(d[1]); var u=new Uint8Array(s.length); for(var i=0;i<s.length;i++) u[i]=s.charCodeAt(i); resolve(new Blob([u],{type:'image/jpeg'})); } catch(e){ reject(e); }
                    }
                });
            }
            async function compressImageFile(file){
                var img=await fileToImage(file);
                var w=img.naturalWidth||img.width, h=img.naturalHeight||img.height;
                if(!w||!h) throw new Error('bad dimensions');
                var maxDim=800;
                var scale=Math.min(1, maxDim/Math.max(w,h));
                // Cap canvas pixels to avoid OOM on huge images
                var nw=Math.max(1, Math.round(w*scale)), nh=Math.max(1, Math.round(h*scale));
                if(nw*nh>2500000){ var s=Math.sqrt(2500000/(nw*nh)); nw=Math.max(1,Math.round(nw*s)); nh=Math.max(1,Math.round(nh*s)); }
                var canvas=document.createElement('canvas'); canvas.width=nw; canvas.height=nh;
                var ctx=canvas.getContext('2d');
                if(!ctx) throw new Error('no ctx');
                ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,nw,nh);
                ctx.imageSmoothingEnabled=true; try{ctx.imageSmoothingQuality='high';}catch(e){}
                ctx.drawImage(img,0,0,nw,nh);
                // Try qualities to hit 300-500KB target: 0.75 -> 0.6 -> 0.5
                var blob=await canvasToBlob(canvas, 0.75);
                if(blob.size>500*1024) blob=await canvasToBlob(canvas, 0.6);
                if(blob.size>500*1024) blob=await canvasToBlob(canvas, 0.5);
                // If still large, reduce dimension to 600
                if(blob.size>500*1024){
                    canvas.width=600; canvas.height=Math.round(nh*(600/nw));
                    ctx=canvas.getContext('2d'); ctx.fillStyle='#ffffff'; ctx.fillRect(0,0,canvas.width,canvas.height);
                    ctx.imageSmoothingEnabled=true; try{ctx.imageSmoothingQuality='high';}catch(e){}
                    ctx.drawImage(img,0,0,canvas.width,canvas.height);
                    blob=await canvasToBlob(canvas, 0.6);
                }
                try{ canvas.width=0; canvas.height=0; }catch(e){}
                return blob;
            }
            function replaceFileInput(input, blob, originalName){
                var name=(originalName||'photo').replace(/\.[^.]+$/,'')+'.jpg';
                var file; try{ file=new File([blob], name, {type:'image/jpeg', lastModified: Date.now()}); } catch(e){ file=blob; try{file.name=name;}catch(_){} }
                if(typeof DataTransfer!=='undefined'){
                    try{ var dt=new DataTransfer(); dt.items.add(file); input.files=dt.files; return true; } catch(e){}
                }
                return false;
            }
            if(fileInput && fileName){
                fileInput.addEventListener('change', function(){
                    var f=fileInput.files && fileInput.files[0];
                    if(f){
                        var origSize=f.size;
                        fileName.textContent = f.name + ' (' + formatKB(origSize) + ')';
                        fileName.style.color='#2271b1';
                        if(clearLink) clearLink.style.display='';
                        // Compress 5-6MB images in browser: resize to 800px max, 70-80% quality -> 300-500KB
                        if(f.size>900*1024){
                            compressing=true;
                            setStatus('Optimizing image...', '#2271b1');
                            // Disable publish until compression done
                            var pubBtn=document.getElementById('publish')||document.getElementById('save-post');
                            if(pubBtn) pubBtn.disabled=true;
                            compressImageFile(f).then(function(blob){
                                if(!blob||!blob.size) throw new Error('empty blob');
                                var ok=replaceFileInput(fileInput, blob, f.name);
                                var outFile=fileInput.files&&fileInput.files[0]?fileInput.files[0]:blob;
                                var saved=origSize-outFile.size;
                                var msg='Optimized — '+formatKB(outFile.size)+(saved>0?' (saved '+formatKB(saved)+')':'');
                                setStatus(msg, '#2271b1');
                                fileName.textContent = (outFile.name||f.name) + ' (' + formatKB(outFile.size) + ') — Optimizing complete';
                                fileName.style.color='#0a7a0a';
                                var reader=new FileReader();
                                reader.onload=function(e){
                                    var preview=document.getElementById('reunion_manual_photo_preview');
                                    if(preview){
                                        preview.style.display='block';
                                        preview.innerHTML='<img src="'+e.target.result+'" alt="preview" style="max-width:200px;height:auto;border:1px solid #dcdcde;border-radius:8px;display:block;"><span style="font-size:12px;color:#0a7a0a;">Compressed to '+formatKB(outFile.size)+' — will upload on Publish/Update</span>';
                                    }
                                };
                                reader.readAsDataURL(outFile);
                            }).catch(function(err){
                                // Graceful fallback: keep original file, show message
                                setStatus('Ready — '+formatKB(origSize)+' (optional optimize failed, will upload original)', '#646970');
                                var reader=new FileReader();
                                reader.onload=function(e){
                                    var preview=document.getElementById('reunion_manual_photo_preview');
                                    if(preview){
                                        preview.style.display='block';
                                        preview.innerHTML='<img src="'+e.target.result+'" alt="preview" style="max-width:200px;height:auto;border:1px solid #dcdcde;border-radius:8px;display:block;"><span style="font-size:12px;color:#646970;">Preview — will upload original</span>';
                                    }
                                };
                                reader.readAsDataURL(f);
                            }).then(function(){
                                compressing=false; setStatus(document.getElementById('reunion_manual_photo_status').textContent||'', '#2271b1');
                                var pubBtn2=document.getElementById('publish')||document.getElementById('save-post');
                                if(pubBtn2) pubBtn2.disabled=false;
                            });
                        } else {
                            setStatus('', '');
                            var reader2=new FileReader();
                            reader2.onload=function(e){
                                var preview=document.getElementById('reunion_manual_photo_preview');
                                if(preview){
                                    preview.style.display='block';
                                    preview.innerHTML='<img src="'+e.target.result+'" alt="preview" style="max-width:200px;height:auto;border:1px solid #dcdcde;border-radius:8px;display:block;"><span style="font-size:12px;color:#2271b1;">Preview — will upload on Publish/Update</span>';
                                }
                            };
                            reader2.readAsDataURL(f);
                        }
                    } else { fileName.textContent='No file chosen'; fileName.style.color='#646970'; if(clearLink) clearLink.style.display='none'; setStatus('', ''); }
                });
                // Block submit while compressing
                var form=fileInput.closest('form');
                if(form){
                    form.addEventListener('submit', function(e){
                        if(compressing){
                            e.preventDefault();
                            setStatus('Optimizing image... please wait', '#2271b1');
                            return false;
                        }
                    });
                }
                if(clearLink){
                    clearLink.addEventListener('click', function(e){
                        e.preventDefault();
                        fileInput.value='';
                        fileName.textContent='No file chosen';
                        fileName.style.color='#646970';
                        clearLink.style.display='none';
                        setStatus('', '');
                        var preview=document.getElementById('reunion_manual_photo_preview');
                        if(preview){ preview.style.display='none'; preview.innerHTML=''; }
                    });
                }
                var rmExisting=document.getElementById('reunion_manual_photo_remove');
                if(rmExisting){
                    rmExisting.addEventListener('click', function(e){
                        e.preventDefault();
                        var preview=document.getElementById('reunion_manual_photo_preview');
                        if(preview){ preview.style.display='none'; preview.innerHTML=''; }
                        fileInput.value='';
                        fileName.textContent='No file chosen';
                        fileName.style.color='#646970';
                        if(clearLink) clearLink.style.display='none';
                        setStatus('', '');
                        // Mark removal: create a flag so save handler can delete meta
                        var flag=document.getElementById('reunion_manual_photo_remove_flag');
                        if(!flag){
                            flag=document.createElement('input');
                            flag.type='hidden';
                            flag.name='reunion_manual_photo_remove';
                            flag.id='reunion_manual_photo_remove_flag';
                            flag.value='1';
                            fileInput.parentNode.appendChild(flag);
                        } else flag.value='1';
                    });
                }
            }
        })();
        </script>
        <?php
    }

    public function handle_manual_add_post_save( $post_id, $post, $update ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
        if ( ! isset( $_POST['reunion_reg_manual_entry_nonce'] ) || ! wp_verify_nonce( $_POST['reunion_reg_manual_entry_nonce'], 'reunion_reg_manual_entry_save' ) ) { return; }
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        if ( ! empty( get_post_meta( $post_id, '_reunion_submitted_at', true ) ) ) { return; }

        $fields = Reunion_Reg_Fields_Schema::get_fields();
        $clean  = array();

        $payment_channel = isset( $_POST['reunion_manual_payment_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['reunion_manual_payment_channel'] ) ) : 'মোবাইল ব্যাংকিং';
        if ( ! in_array( $payment_channel, array( 'মোবাইল ব্যাংকিং', 'ব্যাংক একাউন্ট', 'Cash' ), true ) ) { $payment_channel = 'মোবাইল ব্যাংকিং'; }
        $clean['payment_channel'] = $payment_channel;
        update_post_meta( $post_id, '_reunion_payment_channel', $payment_channel );

        if ( 'Cash' === $payment_channel ) {
            if ( empty( $_POST['reunion_manual_tnx_id'] ) || '' === trim( (string) wp_unslash( $_POST['reunion_manual_tnx_id'] ) ) ) {
                $offline_serial = isset( $_POST['reunion_manual_offline_serial'] ) ? sanitize_text_field( wp_unslash( $_POST['reunion_manual_offline_serial'] ) ) : '';
                $auto_tnx = $offline_serial ? 'CASH-' . preg_replace( '/[^A-Za-z0-9\-]/', '', $offline_serial ) : 'CASH-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false, false );
                $_POST['reunion_manual_tnx_id'] = $auto_tnx;
            }
        }

        foreach ( $fields as $key => $field ) {
            if ( 'file' === $field['type'] ) { continue; }
            if ( in_array( $key, array( 'payment_channel' ), true ) ) { continue; }
            if ( in_array( $key, array( 'mobile_banking_method', 'sender_mobile', 'offline_serial', 'tnx_id' ), true ) ) { continue; }
            if ( 'bank_name' === $key && 'ব্যাংক একাউন্ট' !== $payment_channel ) {
                update_post_meta( $post_id, '_reunion_bank_name', '' );
                $clean[ $key ] = '';
                continue;
            }
            $raw_value = isset( $_POST[ 'reunion_manual_' . $key ] ) ? wp_unslash( $_POST[ 'reunion_manual_' . $key ] ) : '';
            switch ( $field['type'] ) {
                case 'email': $value = sanitize_email( $raw_value ); break;
                case 'tel': $value = sanitize_text_field( preg_replace( '/[^0-9+\-\s()]/', '', $raw_value ) ); break;
                case 'number': case 'computed': $value = is_numeric( $raw_value ) ? (float) $raw_value : 0; break;
                case 'select': case 'radio':
                    $value = sanitize_text_field( $raw_value );
                    if ( '' !== $value && ! empty( $field['options'] ) && ! in_array( $value, array_map( 'strval', $field['options'] ), true ) ) { $value = ''; }
                    break;
                default: $value = sanitize_text_field( $raw_value ); break;
            }
            $clean[ $key ] = $value;
            update_post_meta( $post_id, '_reunion_' . $key, $value );
        }

        // Backend-only fields (kept outside shared schema to leave frontend untouched)
        $mobile_method = isset( $_POST['reunion_manual_mobile_banking_method'] ) ? sanitize_text_field( wp_unslash( $_POST['reunion_manual_mobile_banking_method'] ) ) : '';
        $sender_mobile = isset( $_POST['reunion_manual_sender_mobile'] ) ? sanitize_text_field( preg_replace( '/[^0-9+\-\s()]/', '', wp_unslash( $_POST['reunion_manual_sender_mobile'] ) ) ) : '';
        $offline_serial = isset( $_POST['reunion_manual_offline_serial'] ) ? sanitize_text_field( wp_unslash( $_POST['reunion_manual_offline_serial'] ) ) : '';
        $tnx_id = isset( $_POST['reunion_manual_tnx_id'] ) ? sanitize_text_field( wp_unslash( $_POST['reunion_manual_tnx_id'] ) ) : '';
        $bank_name = isset( $_POST['reunion_manual_bank_name'] ) ? sanitize_text_field( wp_unslash( $_POST['reunion_manual_bank_name'] ) ) : '';

        if ( 'মোবাইল ব্যাংকিং' !== $payment_channel ) { $mobile_method = ''; $sender_mobile = ''; }
        if ( 'ব্যাংক একাউন্ট' !== $payment_channel ) { $bank_name = ''; }

        update_post_meta( $post_id, '_reunion_mobile_banking_method', $mobile_method );
        update_post_meta( $post_id, '_reunion_sender_mobile', $sender_mobile );
        update_post_meta( $post_id, '_reunion_offline_serial', $offline_serial );
        update_post_meta( $post_id, '_reunion_tnx_id', $tnx_id );
        if ( '' !== $bank_name || 'ব্যাংক একাউন্ট' === $payment_channel ) {
            update_post_meta( $post_id, '_reunion_bank_name', $bank_name );
        }
        $clean['tnx_id'] = $tnx_id;
        $clean['mobile_banking_method'] = $mobile_method;
        $clean['sender_mobile'] = $sender_mobile;
        $clean['offline_serial'] = $offline_serial;
        $clean['bank_name'] = $bank_name;

        // Applicant photo — optional direct file upload only (no Media Library)
        if ( ! empty( $_POST['reunion_manual_photo_remove'] ) ) {
            delete_post_meta( $post_id, '_reunion_applicant_photo_id' );
            delete_post_meta( $post_id, '_reunion_applicant_photo_url' );
        } elseif ( isset( $_FILES['reunion_manual_applicant_photo_file'] ) && ! empty( $_FILES['reunion_manual_applicant_photo_file']['tmp_name'] ) && 4 !== (int) ( $_FILES['reunion_manual_applicant_photo_file']['error'] ?? 4 ) ) {
            $file = $_FILES['reunion_manual_applicant_photo_file'];
            if ( 0 === (int) $file['error'] && is_uploaded_file( $file['tmp_name'] ) ) {
                if ( ! function_exists( 'wp_handle_upload' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
                if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
                $check = wp_check_filetype( $file['name'] ?? '', array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
                $allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
                $ok = false;
                if ( ! empty( $check['type'] ) && in_array( strtolower( $check['type'] ), $allowed, true ) ) { $ok = true; }
                if ( ! $ok && ! empty( $file['tmp_name'] ) ) {
                    $finfo = @finfo_open( FILEINFO_MIME_TYPE );
                    if ( $finfo ) { $det = @finfo_file( $finfo, $file['tmp_name'] ); @finfo_close( $finfo ); if ( $det && in_array( strtolower( $det ), $allowed, true ) ) { $ok = true; } }
                    if ( ! $ok ) { $imginfo = @getimagesize( $file['tmp_name'] ); if ( $imginfo && isset( $imginfo['mime'] ) && in_array( strtolower( $imginfo['mime'] ), $allowed, true ) ) { $ok = true; } }
                }
                $size = isset( $file['size'] ) ? (int) $file['size'] : 0;
                if ( $ok && $size > 0 && $size <= 10 * 1024 * 1024 ) {
                    $move = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
                    if ( $move && empty( $move['error'] ) && ! empty( $move['file'] ) ) {
                        $att = array( 'post_mime_type' => $move['type'], 'post_title' => sanitize_file_name( pathinfo( $move['file'], PATHINFO_FILENAME ) ), 'post_content' => '', 'post_status' => 'inherit', 'guid' => $move['url'] );
                        $aid = wp_insert_attachment( $att, $move['file'], $post_id );
                        if ( ! is_wp_error( $aid ) && $aid ) {
                            $meta = wp_generate_attachment_metadata( $aid, $move['file'] );
                            if ( ! empty( $meta ) ) { wp_update_attachment_metadata( $aid, $meta ); }
                            $url = wp_get_attachment_url( $aid ) ?: $move['url'];
                            update_post_meta( $post_id, '_reunion_applicant_photo_id', (int) $aid );
                            update_post_meta( $post_id, '_reunion_applicant_photo_url', esc_url_raw( $url ) );
                        }
                    }
                }
                // invalid/oversize — optional field, silently skip (do not fail save)
            }
        }
        // No file and no removal flag → optional, leave existing photo as-is (do not delete)

        update_post_meta( $post_id, '_reunion_submitted_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_reunion_submitted_ip', 'manual-admin-entry' );
        remove_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10 );
        wp_update_post( array( 'ID' => $post_id, 'post_title' => trim( ( $clean['full_name'] ?? '' ) . ' — ' . $tnx_id, ' —' ) ) );
        add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10, 3 );

        if ( ! empty( $clean['full_name'] ) ) {
            $reg_id = Reunion_Reg_ID_Generator::generate_registration_id( $clean['batch'] ?? '' );
            update_post_meta( $post_id, '_reunion_status', 'approved' );
            update_post_meta( $post_id, '_reunion_reg_id', $reg_id );
            update_post_meta( $post_id, '_reunion_approved_at', current_time( 'mysql' ) );
            do_action( 'reunion_reg_after_approve', $post_id, $reg_id );
        } else {
            update_post_meta( $post_id, '_reunion_status', 'pending' );
        }
    }

    public function handle_edit_post_save( $post_id, $post, $update ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return; }
        if ( get_post_type( $post_id ) !== REUNION_REG_CPT_SLUG ) { return; }
        if ( ! isset( $_POST['reunion_reg_edit_nonce'] ) || ! wp_verify_nonce( $_POST['reunion_reg_edit_nonce'], REUNION_REG_ADMIN_ACTION_NONCE . '_edit_' . $post_id ) ) { return; }
        if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'manage_options' ) ) { return; }
        $has_edit_field = false;
        foreach ( array_keys( Reunion_Reg_Fields_Schema::get_fields() ) as $k ) {
            if ( isset( $_POST[ 'reunion_edit_' . $k ] ) ) { $has_edit_field = true; break; }
        }
        if ( ! $has_edit_field ) { return; }
        $fields = Reunion_Reg_Fields_Schema::get_fields();
        foreach ( $fields as $key => $field ) {
            if ( 'file' === $field['type'] ) { continue; }
            $raw_value = isset( $_POST[ 'reunion_edit_' . $key ] ) ? wp_unslash( $_POST[ 'reunion_edit_' . $key ] ) : '';
            switch ( $field['type'] ) {
                case 'email': $value = sanitize_email( $raw_value ); break;
                case 'tel': $value = sanitize_text_field( preg_replace( '/[^0-9+\-\s()]/', '', $raw_value ) ); break;
                case 'number': case 'computed': $value = is_numeric( $raw_value ) ? (float) $raw_value : 0; break;
                case 'select': case 'radio':
                    $value = sanitize_text_field( $raw_value );
                    if ( '' !== $value && ! empty( $field['options'] ) && ! in_array( $value, array_map( 'strval', $field['options'] ), true ) ) { $value = ''; }
                    break;
                default: $value = sanitize_text_field( $raw_value ); break;
            }
            update_post_meta( $post_id, '_reunion_' . $key, $value );
        }
        if ( isset( $_POST['reunion_edit_sender_mobile'] ) ) { update_post_meta( $post_id, '_reunion_sender_mobile', sanitize_text_field( preg_replace( '/[^0-9+\-\s()]/', '', wp_unslash( $_POST['reunion_edit_sender_mobile'] ) ) ) ); }
        if ( isset( $_POST['reunion_edit_offline_serial'] ) ) { update_post_meta( $post_id, '_reunion_offline_serial', sanitize_text_field( wp_unslash( $_POST['reunion_edit_offline_serial'] ) ) ); }
        if ( isset( $_POST['reunion_edit_mobile_banking_method'] ) ) { update_post_meta( $post_id, '_reunion_mobile_banking_method', sanitize_text_field( wp_unslash( $_POST['reunion_edit_mobile_banking_method'] ) ) ); }
        $name = get_post_meta( $post_id, '_reunion_full_name', true );
        $tnx_id = get_post_meta( $post_id, '_reunion_tnx_id', true );
        $new_title = trim( $name . ' — ' . $tnx_id, ' —' );
        if ( $new_title !== $post->post_title ) {
            remove_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10 );
            remove_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_edit_post_save' ), 10 );
            wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_title ) );
            add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_manual_add_post_save' ), 10, 3 );
            add_action( 'save_post_' . REUNION_REG_CPT_SLUG, array( $this, 'handle_edit_post_save' ), 10, 3 );
        }
    }

    public function filter_redirect_location( $location, $post_id ) {
        if ( ! $post_id || get_post_type( $post_id ) !== REUNION_REG_CPT_SLUG ) { return $location; }
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) { return $location; }
        if ( ! isset( $_POST['reunion_reg_edit_nonce'] ) || ! wp_verify_nonce( $_POST['reunion_reg_edit_nonce'], REUNION_REG_ADMIN_ACTION_NONCE . '_edit_' . $post_id ) ) { return $location; }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return $location; }
        return add_query_arg( 'reunion_admin_notice', 'saved', $location );
    }

    public function render_entry_meta_box( $post ) {
        $fields = Reunion_Reg_Fields_Schema::get_fields();
        $status = Reunion_Reg_CPT::get_status( $post->ID );
        $reg_id = get_post_meta( $post->ID, '_reunion_reg_id', true );
        wp_nonce_field( REUNION_REG_ADMIN_ACTION_NONCE . '_edit_' . $post->ID, 'reunion_reg_edit_nonce' );
        echo '<table class="form-table"><tbody>';
        echo '<tr><th style="width:180px;text-align:left;">Status</th><td>' . Reunion_Reg_CPT::status_badge_html( $status ) . '</td></tr>';
        echo '<tr><th style="text-align:left;">Registration ID</th><td>' . ( $reg_id ? '<strong>' . esc_html( $reg_id ) . '</strong>' : '<em>Not assigned yet (assigned on approval)</em>' ) . '</td></tr>';
        foreach ( $fields as $key => $field ) {
            if ( 'file' === $field['type'] ) { continue; }
            $value = get_post_meta( $post->ID, '_reunion_' . $key, true );
            echo '<tr>';
            echo '<th style="width:180px;text-align:left;">' . esc_html( $field['label'] ) . '</th>';
            echo '<td>';
            if ( in_array( $field['type'], array( 'select', 'radio' ), true ) ) {
                echo '<select name="reunion_edit_' . esc_attr( $key ) . '" id="reunion_edit_' . esc_attr( $key ) . '" style="min-width:220px;">';
                echo '<option value="">-- Select --</option>';
                foreach ( $field['options'] as $option ) {
                    echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
                }
                echo '</select>';
            } elseif ( in_array( $field['type'], array( 'number', 'computed' ), true ) ) {
                echo '<input type="number" name="reunion_edit_' . esc_attr( $key ) . '" id="reunion_edit_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="min-width:140px;">';
            } else {
                echo '<input type="text" name="reunion_edit_' . esc_attr( $key ) . '" id="reunion_edit_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" style="min-width:280px;">';
            }
            echo '</td></tr>';
        }
        $sender_mobile = get_post_meta( $post->ID, '_reunion_sender_mobile', true );
        $offline_serial = get_post_meta( $post->ID, '_reunion_offline_serial', true );
        if ( $sender_mobile !== '' ) { echo '<tr><th style="text-align:left;">প্রেরকের মোবাইল নম্বর</th><td><input type="text" name="reunion_edit_sender_mobile" value="' . esc_attr( $sender_mobile ) . '" style="min-width:280px;"></td></tr>'; }
        if ( $offline_serial !== '' ) { echo '<tr><th style="text-align:left;">অফলাইন সিরিয়াল নম্বর</th><td><input type="text" name="reunion_edit_offline_serial" value="' . esc_attr( $offline_serial ) . '" style="min-width:280px;"></td></tr>'; }
        $mobile_method = get_post_meta( $post->ID, '_reunion_mobile_banking_method', true );
        $pc = get_post_meta( $post->ID, '_reunion_payment_channel', true );
        if ( $mobile_method !== '' && 'মোবাইল ব্যাংকিং' === $pc ) { echo '<tr><th style="text-align:left;">কোন মাধ্যমে দিয়েছেন?</th><td><input type="text" name="reunion_edit_mobile_banking_method" value="' . esc_attr( $mobile_method ) . '" style="min-width:280px;" placeholder="যেমন: bKash Personal, Rocket..."></td></tr>'; }
        $receipt_id  = (int) get_post_meta( $post->ID, '_reunion_payment_receipt_id', true );
        $receipt_url = get_post_meta( $post->ID, '_reunion_payment_receipt_url', true );
        if ( ! $receipt_url && $receipt_id ) { $receipt_url = wp_get_attachment_url( $receipt_id ) ?: ''; }
        if ( $receipt_url ) {
            $receipt_view = $receipt_id ? get_edit_post_link( $receipt_id ) : '';
            echo '<tr><th style="text-align:left;">পেমেন্ট রসিদ</th><td><a href="' . esc_url( $receipt_url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $receipt_url ) . '" alt="Payment receipt" style="max-width:220px;height:auto;border:1px solid #e5e7eb;border-radius:8px;"></a><br><a href="' . esc_url( $receipt_url ) . '" target="_blank" rel="noopener">Open full size</a>' . ( $receipt_view ? ' · <a href="' . esc_url( $receipt_view ) . '">View in Media Library</a>' : '' ) . '</td></tr>';
        }
        $photo_id  = (int) get_post_meta( $post->ID, '_reunion_applicant_photo_id', true );
        $photo_url = get_post_meta( $post->ID, '_reunion_applicant_photo_url', true );
        if ( ! $photo_url && $photo_id ) { $photo_url = wp_get_attachment_url( $photo_id ) ?: ''; }
        if ( $photo_url ) {
            $photo_view = $photo_id ? get_edit_post_link( $photo_id ) : '';
            echo '<tr><th style="text-align:left;">আবেদনকারীর ছবি</th><td><a href="' . esc_url( $photo_url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $photo_url ) . '" alt="Applicant photo" style="max-width:220px;height:auto;border:1px solid #e5e7eb;border-radius:8px;"></a><br><a href="' . esc_url( $photo_url ) . '" target="_blank" rel="noopener">Open full size</a>' . ( $photo_view ? ' · <a href="' . esc_url( $photo_view ) . '">View in Media Library</a>' : '' ) . '</td></tr>';
        }
        $submitted_at = get_post_meta( $post->ID, '_reunion_submitted_at', true );
        echo '<tr><th style="text-align:left;">Submitted At</th><td>' . esc_html( $submitted_at ) . '</td></tr>';
        echo '</tbody></table>';
        $this->render_admin_total_calc_script( 'reunion_edit_guest_count', 'reunion_edit_donation', 'reunion_edit_total_amount' );
        echo '<hr style="margin:16px 0;">';
        if ( $status === 'pending' ) {
            $approve_url = wp_nonce_url( admin_url( 'admin-post.php?action=reunion_reg_approve&post_id=' . $post->ID ), REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post->ID );
            $reject_url = wp_nonce_url( admin_url( 'admin-post.php?action=reunion_reg_reject&post_id=' . $post->ID ), REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post->ID );
            echo '<p>';
            echo '<a href="' . esc_url( $approve_url ) . '" class="button button-primary" style="margin-right:8px;" onclick="return confirm(\'Approve this registration? An email will be sent to the applicant.\');">Approve</a>';
            echo '<a href="' . esc_url( $reject_url ) . '" class="button" onclick="return confirm(\'Reject this registration? An email will be sent to the applicant.\');">Reject</a>';
            echo '</p>';
        } else {
            $other_status = $status === 'approved' ? 'reject' : 'approve';
            $other_label  = $status === 'approved' ? 'Reject Instead' : 'Approve Instead';
            $other_url    = wp_nonce_url( admin_url( 'admin-post.php?action=reunion_reg_' . $other_status . '&post_id=' . $post->ID . '&override=1' ), REUNION_REG_ADMIN_ACTION_NONCE . '_' . $post->ID );
            echo '<p style="color:#666;"><em>This entry has been ' . esc_html( strtolower( Reunion_Reg_Fields_Schema::get_statuses()[ $status ] ) ) . '.</em></p>';
            echo '<p><a href="' . esc_url( $other_url ) . '" class="button" onclick="return confirm(\'Change status to ' . esc_js( $other_status ) . 'd? This will send a notification email to the applicant.\');">' . esc_html( $other_label ) . ' (Override)</a></p>';
        }
    }
}
