
typedef int pid_t;
typedef unsigned int tcflag_t;
typedef unsigned char cc_t;
typedef unsigned int speed_t;
typedef unsigned long nfds_t;
struct termios {
  tcflag_t c_iflag;
  tcflag_t c_oflag;
  tcflag_t c_cflag;
  tcflag_t c_lflag;
  cc_t c_line;
  cc_t c_cc[32];
  speed_t c_ispeed;
  speed_t c_ospeed;
};
struct pollfd {
  int   fd;
  short events;
  short revents;
};

int tcgetattr(int fd, struct termios *termios_p);
int tcsetattr(int fd, int optional_actions, const struct termios *termios_p);
int openpty(int *amaster, int *aslave, char *name, void *termp, void *winp);
int open(const char *path, int flags);
int dup2(int oldfd, int newfd);
int socketpair(int domain, int type, int protocol, int sv[2]);
ssize_t read(int fd, void *buf, size_t count);
ssize_t write(int fd, const void *buf, size_t count);
int close(int fd);
int execvp(const char *file, char *const argv[]);
int fcntl(int fd, int cmd, ...);
pid_t setsid(void);
int poll(struct pollfd *fds, nfds_t nfds, int timeout);
int fileno(void *stream);
int socketpair(int domain, int type, int protocol, int sv[2]);

int *__errno_location(void);
int isatty(int fd);
pid_t tcgetpgrp(int fd);
pid_t getpgrp(void);
int ioctl(int fd, unsigned long request, ...);
int setpgid(pid_t pid, pid_t pgid);
pid_t getpgrp(void);
int tcsetpgrp(int fd, pid_t pgrp);
pid_t tcgetpgrp(int fd);