import pyaudio           # 用于音频采集
import webrtcvad         # 语音活动检测（VAD）
import wave              # 写入 WAV 音频文件
import threading         # 启动子线程
import time              # 时间控制
import collections       # 使用 deque 作为音频缓存
import openai            # 调用 OpenAI Whisper API 进行语音识别
import sys
import requests
import json
import os
from playsound import playsound
from queue import Queue
from threading import Thread
import sounddevice as sd
import soundfile as sf
from funasr import AutoModel



# === 参数配置部分 ===
RATE = 16000                     # 采样率为 16kHz，常用于语音识别
CHANNELS = 1                     # 单声道
FORMAT = pyaudio.paInt16         # 每帧 16 位整型数据
CHUNK_MS = 30                    # 每帧持续时间为 30 毫秒
CHUNK_SIZE = int(RATE * CHUNK_MS / 1000)  # 每块帧大小，单位是采样点数
VAD_MODE = 2                     # VAD 模式（0~3），越高越敏感
SILENCE_TIMEOUT = 3.0           # 如果静音时间超过此值（秒），自动结束录音
OUTPUT_FILE = "recorded.wav"    # 保存录音的输出文件名

openai.api_key = "your_openai_api_key_here"  # 替换为你的 OpenAI API Key

tts_q = Queue()
player_q = Queue()
# ======================

# 该变量为外部信号，用于启动录音（默认关闭）
recording_signal = False

# 函数：修改外部录音信号值（模拟按钮或外部系统触发）
def set_recording_signal(value: bool):
    global recording_signal
    recording_signal = value

# 类：用于语音录制
class MicRecorder:
    def __init__(self):
        self.vad = webrtcvad.Vad(VAD_MODE)       # 初始化语音检测器
        self.pa = pyaudio.PyAudio()              # 初始化 PyAudio
        self.stream = self.pa.open(              # 打开麦克风流
            format=FORMAT,
            channels=CHANNELS,
            rate=RATE,
            input=True,
            frames_per_buffer=CHUNK_SIZE
        )
        # 缓冲静音帧，用于延迟检测结束
        self.buffer = collections.deque(maxlen=int(SILENCE_TIMEOUT * 1000 / CHUNK_MS))

    # 检测一段音频帧是否为语音
    def is_speech(self, frame_bytes):
        return self.vad.is_speech(frame_bytes, RATE)

    # 主录音逻辑：一次性录制直到检测说完
    def record_once(self):
        print("[*] 等待录音信号...")
        # 等待外部信号触发开始录音
        while not recording_signal:
            time.sleep(0.1)

        print("[+] 录音开始，检测语音...")
        frames = []  # 存储有语音的数据
        silent_chunks = collections.deque(maxlen=int(SILENCE_TIMEOUT * 1000 / CHUNK_MS))  # 静音帧缓存

        while True:
            # 每次读取一帧音频
            frame = self.stream.read(CHUNK_SIZE, exception_on_overflow=False)

            if self.is_speech(frame):  # 如果是语音帧
                frames.append(frame)   # 添加到录音帧中
                silent_chunks.clear()  # 清空静音帧缓存
            else:
                if frames:  # 如果已经开始录音
                    silent_chunks.append(frame)  # 累计静音帧
                    if len(silent_chunks) == silent_chunks.maxlen:
                        # 如果静音时间超过阈值，结束录音
                        frames.extend(silent_chunks)  # 最后一段静音也写入
                        print("[-] 静音超时，结束录音")
                        break

        self._save_wav(frames)  # 保存录音
        print(f"[+] 已保存到 {OUTPUT_FILE}")
        return OUTPUT_FILE

    # 将音频帧保存为 .wav 文件
    def _save_wav(self, frames):
        wf = wave.open(OUTPUT_FILE, 'wb')  # 打开输出文件
        wf.setnchannels(CHANNELS)          # 设置声道数
        wf.setsampwidth(self.pa.get_sample_size(FORMAT))  # 设置采样宽度
        wf.setframerate(RATE)              # 设置采样率
        wf.writeframes(b''.join(frames))   # 写入音频数据
        wf.close()

    # 释放音频资源
    def close(self):
        self.stream.stop_stream()
        self.stream.close()
        self.pa.terminate()


def wavtoword(wav_file):
    res = model.generate(input=wav_file, data_type="sound", inference_clip_length=250, disable_update=True)
    word = res[0]['text']
    return word

# 调用 Whisper API 进行语音转文本
def transcribe_with_whisper(filepath,stt_model):
    print("[*] 正在识别音频内容...")
    res = stt_model.generate(input=filepath, data_type="sound", inference_clip_length=250, disable_update=True)
    word_text = res[0]['text']
    print(word_text)
    return word_text


def tts_worker():
    while True:
        st = tts_q.get()
        test_tts(st)
        tts_q.task_done()

def player_worker():
    while True:
        audio = player_q.get()
        play_audio(audio)
        player_q.task_done()

def AI_response(user_text, conversation_id):
    # API 基本信息
    API_URL = "http://192.168.166.144/v1/chat-messages"
    API_KEY = "app-MAzgaqOFk2jgM7wspctReZeG"
    user = "ShowRoom"
    headers = {
        "Content-Type": "application/json",
        "Accept": "text/event-stream", 
        "Authorization": f"Bearer {API_KEY}" 
    }
    
    payload = {
        "inputs": {},
        "query": user_text,
        "response_mode": "streaming",
        "conversation_id": conversation_id,
        "user": user
    }

    try:
        print("Request RAG:",time.ctime())
        response = requests.post(API_URL, json=payload, headers=headers, stream=True)
        response.raise_for_status()
        new_conv_id = None  # 更新后的 conversation_id  
        flag = 0
        stxt = ''
        for line in response.iter_lines():
            if not line:
                continue
            decoded = line.decode('utf-8')
            if decoded.startswith("data:"):
                data = json.loads(decoded[len("data:"):])
                if data.get("answer", "") == "<think>":
                    flag = 1 
                if data.get("answer", "") == "</think>":
                    flag = 0
                    continue
                if flag == 0 :
                    stxt += data.get("answer", "")
                if stxt:
                    if stxt[-1] == '，' or stxt[-1] == '。':
                        print(stxt)
                        test_tts(stxt)
                        stxt = ''
                        
                if not new_conv_id:
                    new_conv_id = data.get("conversation_id", conversation_id)
        print("RAG response:",time.ctime())
       # print()  
        return new_conv_id
   
    except requests.exceptions.RequestException as e:
        print(f"\n[错误] 请求时发生异常: {e}")
        return None
   
   
def test_tts(tts_text):
    spk_id = "中文女"
    stream = True
    data = {
        "spk_id": spk_id,
        "tts_text": tts_text,
        "stream": stream,
        "speed": 1.1,
    }
    st = time.time()  
    if stream:
        print("tts_text:",tts_text)
        response = requests.post("http://192.168.166.144:3005/cosyvoice", json=data)
        print(f"Streaming TTS请求耗时：{time.time() - st}s")
        audio_content = b''
        for chunk in response.iter_content(chunk_size=1024):
            audio_content += chunk
    else:
        response = requests.post("http://192.168.166.144:3005/cosyvoice", json=data)
        print(f"请求耗时：{time.time() - st}s")
        audio_content = base64.b64decode(response.content)
    pcm2wav(audio_content)

def pcm2wav(pcm_data):
    with wave.open('outputs/gen_audio.wav', "wb") as wav:
        wav.setnchannels(1)          # 设置声道数
        wav.setsampwidth(2)      # 设置采样宽度
        wav.setframerate(24000)       # 设置采样率
        wav.writeframes(pcm_data)           # 写入 PCM 数据
    
    playsound('outputs/gen_audio.wav')


def play_audio(sound):
    data, fs = sf.read(sound, dtype='float32')
    sd.play(data, fs)
    sd.wait()


flag_path = "/tmp/flag.txt" 
def monitor_file(file_path):
    last_value = None
    while True:
        try:
            with open(file_path, 'r+') as f:
                line = f.readline().strip()
                if line == "True":
                    print("Detected True. Clearing file.")
                    f.write("False")
                    open(file_path, 'w').close()
                    return "True"
                elif line != last_value:
                    print(f"Current content: {line}")
                last_value = line
        except FileNotFoundError:
            print("File not found. Waiting for it to be created.")
        except Exception as e:
            print(f"Error reading file: {e}")
        time.sleep(0.5)



# 主程序入口
if __name__ == "__main__":
    try:

        #Thread(target=tts_worker, daemon=True).start()
        Thread(target=player_worker, daemon=True).start()
        stt_model = AutoModel(model="/home/b/daniel/funasr-project/FunASR/models",disable_update=True)
        file_path = "/tmp/flag.txt"
        last_value = None
        # 模拟延时触发录音信号（真实场景中可以由 GUI 或 Web 控制）
        while True:
                try:
                    with open(file_path, 'r+') as f:
                        line = f.readline().strip()
                        if line == "True":
                            print("Detected True. Clearing file.")
                            f.write("False")
                            open(file_path, 'w').close()
                            recording_signal = True
                            print("录音开始:",time.ctime())
                            recorder = MicRecorder()  # 初始化录音器
                            filepath = recorder.record_once()
                            print("录音完成:",time.ctime())

                            # 识别音频内容
                            print("识别音频内容:",time.ctime())
                            user_text = transcribe_with_whisper(filepath,stt_model)
                            print("识别音频完成:",time.ctime())
                            conversation_id = None
                            # 呼叫 API
                            conversation_id = AI_response(user_text, conversation_id)


                        elif line != last_value:
                            print(f"Current content: {line}")
                        last_value = line
                except FileNotFoundError:
                    print("File not found. Waiting for it to be created.")
                except Exception as e:
                    print(f"Error reading file: {e}")


    except KeyboardInterrupt:
        pass
    finally:
        recorder.close()  # 清理资源
